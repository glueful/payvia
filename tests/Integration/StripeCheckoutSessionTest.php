<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Payvia\Contracts\InitiationCapableGateway;
use Glueful\Extensions\Payvia\Gateways\StripeGateway;
use Glueful\Http\Client;
use Glueful\Http\Response\Response as HttpResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ResponseInterface as SymfonyResponse;

/**
 * StripeGateway::initialize() — hosted Checkout Sessions (checkout-ui plan Task 1). The session id
 * (`cs_…`) doubles as the provider reference the existing `verify()` session branch already
 * normalizes, so creation is the only new surface. Strict validation runs BEFORE the collector
 * could persist an open intent: a malformed session id or a non-HTTPS checkout URL throws.
 */
final class StripeCheckoutSessionTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private function gateway(Client $http, ?string $secret = 'sk_test_123', array $overrides = []): StripeGateway
    {
        return new StripeGateway($http, $this->context($secret, $overrides));
    }

    /** @param array<string,mixed> $overrides */
    private function context(?string $secret, array $overrides = []): ApplicationContext
    {
        $base = sys_get_temp_dir() . '/payvia-stripe-session-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents($base . '/config/payvia.php', "<?php\nreturn " . var_export([
            'gateways' => [
                'stripe' => array_merge([
                    'secret_key' => $secret,
                    'base_url' => 'https://api.stripe.com',
                    'timeout' => 15,
                ], $overrides),
            ],
        ], true) . ";\n");
        $context = new ApplicationContext($base, 'testing');
        $context->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));

        return $context;
    }

    /** @param array<string,mixed> $decoded */
    private function responseOf(int $statusCode, array $decoded): HttpResponse
    {
        $symfony = $this->createMock(SymfonyResponse::class);
        $symfony->method('toArray')->willReturn($decoded);

        $response = $this->createMock(HttpResponse::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getSymfonyResponse')->willReturn($symfony);
        $response->method('toArray')->willReturn($decoded);

        return $response;
    }

    /**
     * Client whose one POST is captured into a shared holder (an object, so the destructured
     * variable and the callback see the same state).
     *
     * @return array{0: Client, 1: \ArrayObject<string,mixed>}
     */
    private function capturingClient(HttpResponse $response): array
    {
        /** @var \ArrayObject<string,mixed> $captured */
        $captured = new \ArrayObject(['url' => null, 'options' => null]);
        $http = $this->createMock(Client::class);
        $http->expects(self::once())->method('post')
            ->willReturnCallback(function (string $url, array $options) use ($captured, $response): HttpResponse {
                $captured['url'] = $url;
                $captured['options'] = $options;

                return $response;
            });

        return [$http, $captured];
    }

    private function payable(): PayableReference
    {
        return new PayableReference('commerce_order', 'ord42', 4999, 'GHS', 'Order THL-42');
    }

    /** @return array<string,mixed> */
    private function goodSession(): array
    {
        return [
            'id' => 'cs_test_abc123',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_abc123',
            'payment_status' => 'unpaid',
        ];
    }

    public function testImplementsInitiationCapableGateway(): void
    {
        $gateway = $this->gateway($this->createMock(Client::class));

        self::assertInstanceOf(InitiationCapableGateway::class, $gateway);
    }

    public function testCreatesASessionWithTheRightFieldsAndAPerAttemptIdempotencyKey(): void
    {
        [$http, $captured] = $this->capturingClient($this->responseOf(200, $this->goodSession()));

        $result = $this->gateway($http)->initialize($this->payable(), [
            'attempt_uuid' => 'atmpt0000001',
            'email' => 'buyer@example.test',
            'callback_url' => 'https://shop.test/checkout/return/THL-42',
            'cancel_url' => 'https://shop.test/checkout/cancel/THL-42',
        ]);

        self::assertSame('https://api.stripe.com/v1/checkout/sessions', $captured['url']);
        $form = $captured['options']['form_params'];
        self::assertSame('payment', $form['mode']);
        self::assertSame('ord42', $form['client_reference_id']);
        self::assertSame('https://shop.test/checkout/return/THL-42', $form['success_url']);
        self::assertSame('https://shop.test/checkout/cancel/THL-42', $form['cancel_url']);
        self::assertSame('buyer@example.test', $form['customer_email']);
        self::assertSame('ghs', $form['line_items'][0]['price_data']['currency']);
        self::assertSame(4999, $form['line_items'][0]['price_data']['unit_amount']);
        self::assertSame('Order THL-42', $form['line_items'][0]['price_data']['product_data']['name']);
        self::assertSame(1, $form['line_items'][0]['quantity']);
        self::assertSame('commerce_order', $form['metadata']['payable_type']);
        self::assertSame('ord42', $form['metadata']['payable_id']);
        // Per ATTEMPT, not per payable (payment-links Task 2): the collector claims an attempt
        // uuid before any provider I/O and replays it verbatim on a retry, so a timed-out attempt
        // de-dupes onto the SAME session -- while a later, provider-proven renewal claims a NEW
        // attempt uuid and can therefore never collide with the retired one's session id.
        self::assertSame(
            'payvia-init-atmpt0000001',
            $captured['options']['headers']['Idempotency-Key'],
        );

        self::assertSame('cs_test_abc123', $result['reference']);
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_abc123', $result['checkout_url']);
    }

    public function testADifferentAttemptUuidYieldsADifferentIdempotencyKey(): void
    {
        [$first, $firstCaptured] = $this->capturingClient($this->responseOf(200, $this->goodSession()));
        [$second, $secondCaptured] = $this->capturingClient($this->responseOf(200, $this->goodSession()));

        $options = ['callback_url' => 'https://shop.test/r'];
        $this->gateway($first)->initialize($this->payable(), $options + ['attempt_uuid' => 'atmpt0000001']);
        $this->gateway($second)->initialize($this->payable(), $options + ['attempt_uuid' => 'atmpt0000002']);

        self::assertNotSame(
            $firstCaptured['options']['headers']['Idempotency-Key'],
            $secondCaptured['options']['headers']['Idempotency-Key'],
        );
    }

    public function testMissingAttemptUuidThrowsBeforeAnyRequest(): void
    {
        // Structural: there is no code path left that can send a fixed per-payable key.
        $http = $this->createMock(Client::class);
        $http->expects(self::never())->method('post');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('attempt_uuid');
        $this->gateway($http)->initialize($this->payable(), ['callback_url' => 'https://shop.test/r']);
    }

    public function testCancelUrlFallsBackToCallbackAndDescriptionDefaultsToPayment(): void
    {
        [$http, $captured] = $this->capturingClient($this->responseOf(200, $this->goodSession()));
        $payable = new PayableReference('invoice', 'inv7', 100, 'USD');

        $this->gateway($http)->initialize($payable, [
            'attempt_uuid' => 'atmpt0000003',
            'callback_url' => 'https://shop.test/pay/return',
        ]);

        $form = $captured['options']['form_params'];
        self::assertSame('https://shop.test/pay/return', $form['success_url']);
        self::assertSame('https://shop.test/pay/return', $form['cancel_url']);
        self::assertSame('Payment', $form['line_items'][0]['price_data']['product_data']['name']);
        self::assertArrayNotHasKey('customer_email', $form);
    }

    public function testMissingSecretThrowsBeforeAnyRequest(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects(self::never())->method('post');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing Stripe secret key');
        $this->gateway($http, null)->initialize($this->payable(), [
            'attempt_uuid' => 'atmpt0000004',
            'callback_url' => 'https://shop.test/return',
        ]);
    }

    public function testMissingCallbackUrlThrowsBeforeAnyRequest(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects(self::never())->method('post');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('callback_url');
        $this->gateway($http)->initialize($this->payable(), ['attempt_uuid' => 'atmpt0000005']);
    }

    public function testAStripeErrorBodyThrows(): void
    {
        [$http] = $this->capturingClient($this->responseOf(400, [
            'error' => ['type' => 'invalid_request_error', 'message' => 'No such price'],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No such price');
        $this->gateway($http)->initialize($this->payable(), $this->options());
    }

    /** @dataProvider malformedSessions */
    public function testAMalformedSessionResponseThrowsBeforePersistence(array $decoded, string $needle): void
    {
        [$http] = $this->capturingClient($this->responseOf(200, $decoded));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($needle);
        $this->gateway($http)->initialize($this->payable(), $this->options());
    }

    /** @return array<string, array{array<string,mixed>, string}> */
    public static function malformedSessions(): array
    {
        return [
            'missing id' => [['url' => 'https://checkout.stripe.com/c/x'], 'session id'],
            'non-cs id' => [
                ['id' => 'sub_123', 'url' => 'https://checkout.stripe.com/c/x'],
                'session id',
            ],
            'missing url' => [['id' => 'cs_test_1'], 'checkout URL'],
            'non-https url' => [
                ['id' => 'cs_test_1', 'url' => 'http://checkout.stripe.com/c/x'],
                'checkout URL',
            ],
        ];
    }

    // ==================================================================
    // The provider-host trust boundary (payment-links Task 2, spec §2.1)
    // ==================================================================

    public function testATrustedCheckoutHostIsAcceptedCaseInsensitively(): void
    {
        [$http] = $this->capturingClient($this->responseOf(200, [
            'id' => 'cs_test_1',
            'url' => 'https://CHECKOUT.Stripe.COM/c/pay/cs_test_1',
        ]));

        $result = $this->gateway($http)->initialize($this->payable(), $this->options());

        self::assertSame('https://CHECKOUT.Stripe.COM/c/pay/cs_test_1', $result['checkout_url']);
    }

    /** @dataProvider hostileUrls */
    public function testAnUntrustedCheckoutUrlNeverLeavesTheGateway(mixed $url): void
    {
        $session = ['id' => 'cs_test_1'];
        if ($url !== null) {
            $session['url'] = $url;
        }
        [$http] = $this->capturingClient($this->responseOf(200, $session));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('checkout URL');
        $this->gateway($http)->initialize($this->payable(), $this->options());
    }

    /** @return array<string, array{mixed}> */
    public static function hostileUrls(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'malformed' => ['https://'],
            'not a url at all' => ['checkout.stripe.com/c/x'],
            'http' => ['http://checkout.stripe.com/c/x'],
            'userinfo' => ['https://checkout.stripe.com@evil.test/c/x'],
            'credentials' => ['https://user:pass@checkout.stripe.com/c/x'],
            'explicit port' => ['https://checkout.stripe.com:443/c/x'],
            'trailing dot' => ['https://checkout.stripe.com./c/x'],
            'subdomain lookalike' => ['https://checkout.stripe.com.evil.test/c/x'],
            'prefix lookalike' => ['https://xcheckout.stripe.com/c/x'],
            'subdomain of the trusted host' => ['https://a.checkout.stripe.com/c/x'],
            'untrusted host' => ['https://evil.test/c/x'],
            'leading whitespace' => [' https://checkout.stripe.com/c/x'],
            'not a string' => [['https://checkout.stripe.com/c/x']],
        ];
    }

    public function testConfiguredCheckoutHostsAreTheAuthority(): void
    {
        [$http] = $this->capturingClient($this->responseOf(200, [
            'id' => 'cs_test_1',
            'url' => 'https://pay.sandbox.test/c/pay/cs_test_1',
        ]));

        $result = $this->gateway($http, 'sk_test_123', ['checkout_hosts' => ['pay.sandbox.test']])
            ->initialize($this->payable(), $this->options());

        self::assertSame('https://pay.sandbox.test/c/pay/cs_test_1', $result['checkout_url']);
    }

    public function testTheShippedDefaultHostIsRejectedOnceConfigNarrowsTheSet(): void
    {
        [$http] = $this->capturingClient($this->responseOf(200, $this->goodSession()));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('checkout URL');
        $this->gateway($http, 'sk_test_123', ['checkout_hosts' => ['pay.sandbox.test']])
            ->initialize($this->payable(), $this->options());
    }

    /** @return array<string,mixed> */
    private function options(): array
    {
        return ['attempt_uuid' => 'atmpt0000009', 'callback_url' => 'https://shop.test/r'];
    }

    public function testTheReturnedReferenceRoundTripsIntoVerifysSessionBranch(): void
    {
        // Creation → verification, end to end: the reference initialize() returns is exactly what
        // the existing verify() checkout-session branch resolves and normalizes.
        [$createHttp] = $this->capturingClient($this->responseOf(200, $this->goodSession()));
        $reference = $this->gateway($createHttp)
            ->initialize($this->payable(), $this->options())['reference'];

        $verifyHttp = $this->createMock(Client::class);
        $verifyHttp->expects(self::once())->method('get')
            ->with(self::stringContains('/v1/checkout/sessions/' . $reference))
            ->willReturn($this->responseOf(200, [
                'id' => $reference,
                'payment_intent' => 'pi_1',
                'payment_status' => 'paid',
                'amount_total' => 4999,
                'currency' => 'ghs',
            ]));

        $verified = $this->gateway($verifyHttp)->verify($reference);

        self::assertSame('success', $verified['status']);
        self::assertSame($reference, $verified['reference']);
        self::assertSame(4999, $verified['amount']);
        self::assertSame('GHS', $verified['currency']);
    }
}
