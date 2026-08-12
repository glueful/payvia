<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit\Gateways;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\Payvia\Checkout\DefinitiveSubscriptionCheckoutRejection;
use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutRequest;
use Glueful\Extensions\Payvia\Contracts\SubscriptionCancellationModeProvider;
use Glueful\Extensions\Payvia\Contracts\SubscriptionCheckoutLifecycleCapableGateway;
use Glueful\Extensions\Payvia\Contracts\SubscriptionInitiationCapableGateway;
use Glueful\Extensions\Payvia\Gateways\StripeGateway;
use Glueful\Http\Client;
use Glueful\Http\Response\Response as HttpResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ResponseInterface as SymfonyResponse;

/**
 * StripeGateway's subscription-checkout driver (workspace checkout program, Phase A Task 1):
 * `initializeSubscription()` mirrors `initialize()`'s validation/error style but creates a
 * `mode=subscription` Checkout Session instead, and `subscriptionCheckoutStatus()` /
 * `abandonSubscriptionCheckout()` reconcile it afterwards without waiting for a webhook.
 */
final class StripeSubscriptionCheckoutTest extends TestCase
{
    private function gateway(Client $http, ?string $secret = 'sk_test_123'): StripeGateway
    {
        return new StripeGateway($http, $this->context($secret));
    }

    private function context(?string $secret): ApplicationContext
    {
        $base = sys_get_temp_dir() . '/payvia-stripe-subcheckout-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents($base . '/config/payvia.php', "<?php\nreturn " . var_export([
            'gateways' => [
                'stripe' => [
                    'secret_key' => $secret,
                    'base_url' => 'https://api.stripe.com',
                    'timeout' => 15,
                ],
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

    private function request(array $overrides = []): SubscriptionCheckoutRequest
    {
        $defaults = [
            'originationUuid' => 'orig_abc123',
            'tenantUuid' => 'tenant_1',
            'subjectKey' => 'tenant:tenant_1',
            'gateway' => 'stripe',
            'providerPlanIdentifier' => 'price_123',
            'consumerMetadata' => ['tenant_uuid' => 'tenant_1'],
            'customerEmail' => 'buyer@example.test',
            'returnUrl' => 'https://admin.test/billing/return?origination=orig_abc123',
            'cancelUrl' => 'https://admin.test/billing',
            'idempotencyKey' => 'caller-key-1',
            'requiredProjectionConsumer' => 'subscriptions',
        ];
        $values = array_replace($defaults, $overrides);

        return new SubscriptionCheckoutRequest(
            originationUuid: $values['originationUuid'],
            tenantUuid: $values['tenantUuid'],
            subjectKey: $values['subjectKey'],
            gateway: $values['gateway'],
            providerPlanIdentifier: $values['providerPlanIdentifier'],
            consumerMetadata: $values['consumerMetadata'],
            customerEmail: $values['customerEmail'],
            returnUrl: $values['returnUrl'],
            cancelUrl: $values['cancelUrl'],
            idempotencyKey: $values['idempotencyKey'],
            requiredProjectionConsumer: $values['requiredProjectionConsumer'],
        );
    }

    /** @return array<string,mixed> */
    private function goodSession(): array
    {
        return [
            'id' => 'cs_test_sub_abc123',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_sub_abc123',
            'status' => 'open',
            'expires_at' => 1_999_999_999,
        ];
    }

    public function testImplementsTheSubscriptionCheckoutContracts(): void
    {
        $gateway = $this->gateway($this->createMock(Client::class));

        self::assertInstanceOf(SubscriptionInitiationCapableGateway::class, $gateway);
        self::assertInstanceOf(SubscriptionCheckoutLifecycleCapableGateway::class, $gateway);
        self::assertInstanceOf(SubscriptionCancellationModeProvider::class, $gateway);
    }

    public function testTheRequestDtoRetainsTheCallersIdempotencyKey(): void
    {
        $request = $this->request(['idempotencyKey' => 'caller-supplied-key-42']);

        self::assertSame('caller-supplied-key-42', $request->idempotencyKey);
    }

    public function testCreatesASubscriptionSessionWithTheRightFieldsAndProviderIdempotencyKey(): void
    {
        [$http, $captured] = $this->capturingClient($this->responseOf(200, $this->goodSession()));

        $result = $this->gateway($http)->initializeSubscription($this->request());

        self::assertSame('https://api.stripe.com/v1/checkout/sessions', $captured['url']);
        $form = $captured['options']['form_params'];
        self::assertSame('subscription', $form['mode']);
        self::assertSame('price_123', $form['line_items'][0]['price']);
        self::assertSame(1, $form['line_items'][0]['quantity']);
        self::assertSame('orig_abc123', $form['client_reference_id']);
        self::assertSame('orig_abc123', $form['metadata']['origination_uuid']);
        // Named assertion: session metadata does NOT propagate to the subscription object --
        // subscription_data.metadata is the documented mechanism that does.
        self::assertSame('orig_abc123', $form['subscription_data']['metadata']['origination_uuid']);
        self::assertSame(
            'https://admin.test/billing/return?origination=orig_abc123',
            $form['success_url'],
        );
        self::assertSame('https://admin.test/billing', $form['cancel_url']);
        self::assertSame('buyer@example.test', $form['customer_email']);
        self::assertSame(
            'payvia-subinit-orig_abc123',
            $captured['options']['headers']['Idempotency-Key'],
        );

        self::assertSame('cs_test_sub_abc123', $result['reference']);
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_sub_abc123', $result['checkout_url']);
        self::assertSame('2033-05-18 03:33:19', $result['expires_at']);
        self::assertSame($this->goodSession(), $result['raw']);
    }

    public function testCancelUrlIsOptionalAndFallsBackToTheSuccessUrl(): void
    {
        [$http, $captured] = $this->capturingClient($this->responseOf(200, $this->goodSession()));

        $this->gateway($http)->initializeSubscription($this->request(['cancelUrl' => '']));

        $form = $captured['options']['form_params'];
        self::assertSame($form['success_url'], $form['cancel_url']);
    }

    public function testMissingSecretThrowsBeforeAnyRequest(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects(self::never())->method('post');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing Stripe secret key');
        $this->gateway($http, null)->initializeSubscription($this->request());
    }

    public function testMissingSuccessUrlThrowsBeforeAnyRequest(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects(self::never())->method('post');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('success URL');
        $this->gateway($http)->initializeSubscription($this->request(['returnUrl' => '  ']));
    }

    public function testAValidatedProviderRejectionThrowsTheDefinitiveTypedException(): void
    {
        [$http] = $this->capturingClient($this->responseOf(400, [
            'error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such price'],
        ]));

        try {
            $this->gateway($http)->initializeSubscription($this->request());
            self::fail('Expected DefinitiveSubscriptionCheckoutRejection to be thrown');
        } catch (DefinitiveSubscriptionCheckoutRejection $e) {
            self::assertStringContainsString('No such price', $e->getMessage());
            self::assertSame('stripe', $e->gateway);
            self::assertSame('resource_missing', $e->providerCode);
        }
    }

    /**
     * The malformed-response boundary: a body that merely LOOKS like a provider error (an
     * `error` key present) but carries no usable message is NOT a validated rejection -- the
     * outcome is unknown, so this must stay a plain exception, never the typed one.
     */
    public function testAnUnparseableErrorBodyIsAnUnknownFailureNotTheTypedRejection(): void
    {
        [$http] = $this->capturingClient($this->responseOf(400, [
            'error' => ['type' => 'invalid_request_error'],
        ]));

        try {
            $this->gateway($http)->initializeSubscription($this->request());
            self::fail('Expected an exception to be thrown');
        } catch (DefinitiveSubscriptionCheckoutRejection $e) {
            self::fail('An unparseable error body must not become the typed definitive rejection');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(DefinitiveSubscriptionCheckoutRejection::class, $e);
        }
    }

    /** @dataProvider malformedSessions */
    public function testAMalformedSessionResponseIsAnUnknownFailure(array $decoded, string $needle): void
    {
        [$http] = $this->capturingClient($this->responseOf(200, $decoded));

        try {
            $this->gateway($http)->initializeSubscription($this->request());
            self::fail('Expected an exception to be thrown');
        } catch (DefinitiveSubscriptionCheckoutRejection $e) {
            self::fail('A malformed 200 response must not become the typed definitive rejection');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($needle, $e->getMessage());
        }
    }

    /**
     * The 2.6.0 hosted-redirect trust boundary, on the subscription path (payment-links Task 2).
     *
     * Classified DEFINITIVE rather than unknown, unlike the malformed cases above: Stripe DID
     * create the session and its url is one this platform will never redirect to, so replaying
     * the call under the same idempotency key returns the identical refused url. As an unknown
     * outcome it would release the lease and retry forever.
     *
     * @dataProvider untrustedSubscriptionCheckoutUrls
     */
    public function testAnUntrustedCheckoutUrlIsADefinitiveRejection(string $url): void
    {
        [$http] = $this->capturingClient($this->responseOf(200, [
            'id' => 'cs_test_sub_1',
            'url' => $url,
        ]));

        try {
            $this->gateway($http)->initializeSubscription($this->request());
            self::fail('Expected an exception to be thrown');
        } catch (DefinitiveSubscriptionCheckoutRejection $e) {
            self::assertSame('stripe', $e->gateway);
            self::assertSame('untrusted_checkout_url', $e->providerCode);
            self::assertStringContainsString(DefinitiveSubscriptionCheckoutRejection::MARKER, $e->getMessage());
        }
    }

    /** @return array<string, array{string}> */
    public static function untrustedSubscriptionCheckoutUrls(): array
    {
        return [
            'untrusted host' => ['https://evil.test/c/pay/cs_test_sub_1'],
            'subdomain lookalike' => ['https://checkout.stripe.com.evil.test/c/x'],
            'userinfo' => ['https://checkout.stripe.com@evil.test/c/x'],
            'explicit port' => ['https://checkout.stripe.com:8443/c/x'],
            'trailing dot' => ['https://checkout.stripe.com./c/x'],
        ];
    }

    public function testATrustedSubscriptionCheckoutUrlStillPassesThrough(): void
    {
        [$http] = $this->capturingClient($this->responseOf(200, $this->goodSession()));

        $result = $this->gateway($http)->initializeSubscription($this->request());

        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_sub_abc123', $result['checkout_url']);
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

    /**
     * Regression guard: subscription checkout must never silently route through the one-time
     * `initialize()` flow. `StripeGateway` is final (cannot be partially mocked), so this spies
     * at the one seam both methods actually share -- the HTTP client's `post()`. `initialize()`
     * issues its own POST shaped as `mode=payment` with a `price_data` line item; asserting
     * EXACTLY ONE post() call occurred (via `self::once()`, which fails the test the instant a
     * second call is made) AND that it is shaped as a subscription request proves
     * `initializeSubscription()` never delegates through `initialize()`.
     */
    public function testNeverCallsTheOneTimeInitializeMethod(): void
    {
        [$http, $captured] = $this->capturingClient($this->responseOf(200, $this->goodSession()));

        $this->gateway($http)->initializeSubscription($this->request());

        $form = $captured['options']['form_params'];
        self::assertSame('subscription', $form['mode']);
        self::assertArrayNotHasKey('price_data', $form['line_items'][0]);
    }

    /** @dataProvider statusMappings */
    public function testSubscriptionCheckoutStatusMapsProviderShapesToTheFiveValueEnum(
        array $decoded,
        string $expected
    ): void {
        $http = $this->createMock(Client::class);
        $http->expects(self::once())->method('get')
            ->with(self::stringContains('/v1/checkout/sessions/cs_test_sub_abc123'))
            ->willReturn($this->responseOf(200, $decoded));

        $status = $this->gateway($http)->subscriptionCheckoutStatus('cs_test_sub_abc123');

        self::assertSame($expected, $status);
    }

    /** @return array<string, array{array<string,mixed>, string}> */
    public static function statusMappings(): array
    {
        return [
            'open session is pending' => [['status' => 'open', 'payment_status' => 'unpaid'], 'pending'],
            'complete + paid is completed' => [['status' => 'complete', 'payment_status' => 'paid'], 'completed'],
            'complete + no_payment_required is completed' => [
                ['status' => 'complete', 'payment_status' => 'no_payment_required'],
                'completed',
            ],
            // Stripe: an async payment method can leave the SESSION complete while the
            // PAYMENT is still unsettled -- this must stay 'pending', explicitly NOT
            // 'completed', until payment_status itself confirms paid/no_payment_required.
            'complete + unpaid is pending, not completed' => [
                ['status' => 'complete', 'payment_status' => 'unpaid'],
                'pending',
            ],
            'expired session is expired' => [['status' => 'expired'], 'expired'],
            'canceled session is canceled' => [['status' => 'canceled'], 'canceled'],
            'unrecognized status is unknown' => [['status' => 'something_new'], 'unknown'],
            'missing status is unknown' => [[], 'unknown'],
        ];
    }

    public function testAbandoningAnOpenSessionExpiresItAndReportsStillLiveOrConfirmedDead(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects(self::once())->method('post')
            ->with(self::stringContains('/v1/checkout/sessions/cs_test_sub_abc123/expire'))
            ->willReturn($this->responseOf(200, ['id' => 'cs_test_sub_abc123', 'status' => 'expired']));
        $http->expects(self::once())->method('get')
            ->with(self::stringContains('/v1/checkout/sessions/cs_test_sub_abc123'))
            ->willReturn($this->responseOf(200, ['id' => 'cs_test_sub_abc123', 'status' => 'expired']));

        $outcome = $this->gateway($http)->abandonSubscriptionCheckout('cs_test_sub_abc123');

        self::assertSame('confirmed_dead', $outcome);
    }

    public function testAbandoningAnAlreadyCompletedSessionReportsStillLive(): void
    {
        $http = $this->createMock(Client::class);
        // Stripe rejects expiring an already-completed session; the re-fetch is still the
        // single source of truth for the final classification regardless of that error.
        $http->expects(self::once())->method('post')
            ->with(self::stringContains('/expire'))
            ->willReturn($this->responseOf(400, [
                'error' => ['message' => 'You cannot expire a Checkout Session that is complete'],
            ]));
        $http->expects(self::once())->method('get')
            ->willReturn($this->responseOf(200, ['id' => 'cs_test_sub_abc123', 'status' => 'complete']));

        $outcome = $this->gateway($http)->abandonSubscriptionCheckout('cs_test_sub_abc123');

        self::assertSame('still_live', $outcome);
    }

    public function testAbandoningWithAnUnrecognizableFinalStateReportsUnknown(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('post')->willReturn($this->responseOf(200, ['id' => 'cs_test_sub_abc123']));
        $http->method('get')->willReturn($this->responseOf(200, []));

        $outcome = $this->gateway($http)->abandonSubscriptionCheckout('cs_test_sub_abc123');

        self::assertSame('unknown', $outcome);
    }

    public function testStripeDeclaresBothSelfServeCancellationModes(): void
    {
        $gateway = $this->gateway($this->createMock(Client::class));

        self::assertSame(['stop_renewal', 'immediate'], $gateway->cancellationModes());
    }
}
