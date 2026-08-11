<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit\Gateways;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Payvia\Contracts\HostedSessionRenewalCapableGateway;
use Glueful\Extensions\Payvia\Contracts\HostedSessionStateCapableGateway;
use Glueful\Extensions\Payvia\Contracts\ResumableHostedSessionGateway;
use Glueful\Extensions\Payvia\Gateways\PaystackGateway;
use Glueful\Http\Client;
use Glueful\Http\Response\Response as HttpResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ResponseInterface as SymfonyResponse;

/**
 * Paystack's hosted-session surface (payment-links Task 2, spec §2.1 / Rulings 5 and 6).
 *
 * Three pins:
 *  - the transaction `reference` is DERIVED from the attempt uuid the collector claimed before any
 *    provider I/O, so replaying a timed-out attempt re-initializes the SAME reference instead of
 *    minting a second transaction;
 *  - `authorization_url` — until now returned unchecked — must pass the provider-host trust
 *    boundary before it can become an intent payload's checkout url;
 *  - Paystack is liveness-capable but NOT renewal-capable: a new initialization does not prove an
 *    old authorization url dead, so 2.6.0 ships no renewal at all (Ruling 6).
 */
final class PaystackCheckoutSessionTest extends TestCase
{
    /** @param array<string,mixed> $gatewayConfig */
    private function gateway(Client $http, array $gatewayConfig = []): PaystackGateway
    {
        $base = sys_get_temp_dir() . '/payvia-paystack-session-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents($base . '/config/payvia.php', "<?php\nreturn " . var_export([
            'gateways' => [
                'paystack' => array_merge([
                    'secret_key' => 'sk_test_123',
                    'base_url' => 'https://api.paystack.co',
                    'timeout' => 15,
                ], $gatewayConfig),
            ],
        ], true) . ";\n");
        $context = new ApplicationContext($base, 'testing');
        $context->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));

        return new PaystackGateway($http, $context);
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
     * @param array<string,mixed> $data
     * @return array{0: Client, 1: \ArrayObject<string,mixed>}
     */
    private function capturingClient(array $data, int $statusCode = 200): array
    {
        /** @var \ArrayObject<string,mixed> $captured */
        $captured = new \ArrayObject(['url' => null, 'options' => null]);
        $http = $this->createMock(Client::class);
        $http->method('post')->willReturnCallback(
            function (string $url, array $options) use ($captured, $data, $statusCode): HttpResponse {
                $captured['url'] = $url;
                $captured['options'] = $options;

                return $this->responseOf($statusCode, ['status' => true, 'data' => $data]);
            }
        );

        return [$http, $captured];
    }

    private function payable(): PayableReference
    {
        return new PayableReference('commerce_order', 'ord1', 4999, 'GHS');
    }

    public function testTheReferenceIsDerivedFromTheClaimedAttemptUuid(): void
    {
        [$http, $captured] = $this->capturingClient([
            'reference' => 'commerce_order_ord1_attempt00001',
            'authorization_url' => 'https://checkout.paystack.com/abc123',
        ]);

        $result = $this->gateway($http)->initialize($this->payable(), [
            'attempt_uuid' => 'attempt00001',
            'email' => 'buyer@example.test',
        ]);

        self::assertSame('https://api.paystack.co/transaction/initialize', $captured['url']);
        self::assertSame('commerce_order_ord1_attempt00001', $captured['options']['json']['reference']);
        self::assertSame('commerce_order_ord1_attempt00001', $result['reference']);
        self::assertSame('https://checkout.paystack.com/abc123', $result['checkout_url']);
    }

    public function testTheSameAttemptUuidAlwaysDerivesTheSameReference(): void
    {
        // The whole point of per-attempt idempotency: a retried attempt re-initializes the SAME
        // Paystack reference, which Paystack itself de-dupes -- never a second transaction.
        [$first] = $this->capturingClient([
            'reference' => 'commerce_order_ord1_attempt00002',
            'authorization_url' => 'https://checkout.paystack.com/abc',
        ]);
        [$second] = $this->capturingClient([
            'reference' => 'commerce_order_ord1_attempt00002',
            'authorization_url' => 'https://checkout.paystack.com/abc',
        ]);

        $options = ['attempt_uuid' => 'attempt00002'];
        self::assertSame(
            $this->gateway($first)->initialize($this->payable(), $options)['reference'],
            $this->gateway($second)->initialize($this->payable(), $options)['reference'],
        );
    }

    public function testInitializeWithoutAnAttemptUuidThrowsBeforeAnyRequest(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects(self::never())->method('post');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('attempt_uuid');
        $this->gateway($http)->initialize($this->payable(), []);
    }

    public function testATrustedAuthorizationUrlIsAcceptedCaseInsensitively(): void
    {
        [$http] = $this->capturingClient([
            'reference' => 'ref1',
            'authorization_url' => 'https://Checkout.PAYSTACK.com/xyz',
        ]);

        $result = $this->gateway($http)->initialize($this->payable(), ['attempt_uuid' => 'a1']);

        self::assertSame('https://Checkout.PAYSTACK.com/xyz', $result['checkout_url']);
    }

    /** @dataProvider hostileUrls */
    public function testAnUntrustedAuthorizationUrlNeverBecomesACheckoutUrl(mixed $url): void
    {
        $data = ['reference' => 'ref1'];
        if ($url !== null) {
            $data['authorization_url'] = $url;
        }
        [$http] = $this->capturingClient($data);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('checkout URL');
        $this->gateway($http)->initialize($this->payable(), ['attempt_uuid' => 'a1']);
    }

    /** @return array<string, array{mixed}> */
    public static function hostileUrls(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'malformed' => ['https://'],
            'not a url at all' => ['checkout.paystack.com/abc'],
            'http' => ['http://checkout.paystack.com/abc'],
            'userinfo' => ['https://checkout.paystack.com@evil.test/abc'],
            'credentials' => ['https://user:pass@checkout.paystack.com/abc'],
            'explicit port' => ['https://checkout.paystack.com:443/abc'],
            'trailing dot' => ['https://checkout.paystack.com./abc'],
            'subdomain lookalike' => ['https://checkout.paystack.com.evil.test/abc'],
            'prefix lookalike' => ['https://evilcheckout.paystack.com/abc'],
            'subdomain of the trusted host' => ['https://a.checkout.paystack.com/abc'],
            'untrusted host' => ['https://evil.test/abc'],
            'leading whitespace' => [' https://checkout.paystack.com/abc'],
        ];
    }

    public function testConfiguredCheckoutHostsAreTheAuthority(): void
    {
        [$http] = $this->capturingClient([
            'reference' => 'ref1',
            'authorization_url' => 'https://pay.sandbox.test/abc',
        ]);

        $result = $this->gateway($http, ['checkout_hosts' => ['pay.sandbox.test']])
            ->initialize($this->payable(), ['attempt_uuid' => 'a1']);

        self::assertSame('https://pay.sandbox.test/abc', $result['checkout_url']);
    }

    public function testTheShippedDefaultHostIsRejectedOnceConfigNarrowsTheSet(): void
    {
        [$http] = $this->capturingClient([
            'reference' => 'ref1',
            'authorization_url' => 'https://checkout.paystack.com/abc',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('checkout URL');
        $this->gateway($http, ['checkout_hosts' => ['pay.sandbox.test']])
            ->initialize($this->payable(), ['attempt_uuid' => 'a1']);
    }

    // ==================================================================
    // Liveness: confirmed-live reuse only, never renewal (Ruling 6)
    // ==================================================================

    public function testIsLivenessCapableButNeverRenewalCapable(): void
    {
        $gateway = $this->gateway($this->createMock(Client::class));

        self::assertInstanceOf(HostedSessionStateCapableGateway::class, $gateway);
        self::assertNotInstanceOf(HostedSessionRenewalCapableGateway::class, $gateway);
    }

    /**
     * @param array<string,mixed> $decoded
     * @dataProvider verifyStates
     */
    public function testHostedSessionStateMapsVerifyOntoTheSharedEnum(
        int $statusCode,
        array $decoded,
        string $expected
    ): void {
        $http = $this->createMock(Client::class);
        $http->expects(self::once())->method('get')
            ->with('https://api.paystack.co/transaction/verify/ref1')
            ->willReturn($this->responseOf($statusCode, $decoded));
        $http->expects(self::never())->method('post');

        self::assertSame($expected, $this->gateway($http)->hostedSessionState('ref1'));
    }

    /** @return array<string, array{int, array<string,mixed>, string}> */
    public static function verifyStates(): array
    {
        return [
            // Paystack's own not-yet-terminal transaction states: the reference still awaits
            // payment, so its authorization url is reused as-is.
            'abandoned is live' => [200, ['status' => true, 'data' => ['status' => 'abandoned']], 'live'],
            'ongoing is live' => [200, ['status' => true, 'data' => ['status' => 'ongoing']], 'live'],
            'pending is live' => [200, ['status' => true, 'data' => ['status' => 'pending']], 'live'],
            'processing is live' => [200, ['status' => true, 'data' => ['status' => 'processing']], 'live'],
            'queued is live' => [200, ['status' => true, 'data' => ['status' => 'queued']], 'live'],
            // A decline is NOT terminal for a checkout reference: Paystack's guidance is that the
            // customer completes the SAME transaction with another payment option on the same
            // checkout page, and the transaction log counts multiple attempts per reference.
            // Calling it dead would permanently kill the order, since this driver cannot renew.
            'failed is still live' => [200, ['status' => true, 'data' => ['status' => 'failed']], 'live'],
            'success is completed' => [200, ['status' => true, 'data' => ['status' => 'success']], 'completed'],
            // Unreachable without a prior success, so the checkout url is spent either way.
            'reversed is dead' => [200, ['status' => true, 'data' => ['status' => 'reversed']], 'dead'],
            'unknown reference is unknown' => [
                404,
                ['status' => false, 'message' => 'Transaction reference not found'],
                'unknown',
            ],
            'api failure is unknown' => [200, ['status' => false, 'message' => 'nope'], 'unknown'],
            'unrecognized status is unknown' => [200, ['status' => true, 'data' => ['status' => 'x']], 'unknown'],
            'missing data is unknown' => [200, ['status' => true], 'unknown'],
        ];
    }

    public function testAnEmptyReferenceIsNeverProbed(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects(self::never())->method('get');

        self::assertSame('unknown', $this->gateway($http)->hostedSessionState(''));
    }

    public function testProbingWithoutASecretThrowsBeforeAnyRequest(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects(self::never())->method('get');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing Paystack secret key');
        $this->gateway($http, ['secret_key' => null])->hostedSessionState('ref1');
    }

    // ==================================================================
    // Resuming an interrupted attempt: verify FIRST, never re-POST a used
    // reference (fix round 1, Critical 1)
    // ==================================================================

    public function testIsResumeCapableBecauseItsCreateCallIsNotRepeatable(): void
    {
        self::assertInstanceOf(
            ResumableHostedSessionGateway::class,
            $this->gateway($this->createMock(Client::class)),
        );
    }

    /**
     * @param array<string,mixed> $decoded
     * @dataProvider resumeOutcomes
     */
    public function testResumeVerifiesTheDerivedReferenceAndNeverReInitializes(
        int $statusCode,
        array $decoded,
        string $expected
    ): void {
        $http = $this->createMock(Client::class);
        $http->expects(self::once())->method('get')
            // The SAME reference initialize() would have derived -- that is the whole point.
            ->with('https://api.paystack.co/transaction/verify/commerce_order_ord1_atmpt1')
            ->willReturn($this->responseOf($statusCode, $decoded));
        // Re-POSTing /transaction/initialize with a used reference is a permanent HTTP 400
        // "Duplicate Transaction Reference"; resume must never do it.
        $http->expects(self::never())->method('post');

        $resumed = $this->gateway($http)->resumeHostedSession($this->payable(), ['attempt_uuid' => 'atmpt1']);

        self::assertSame($expected, $resumed['outcome']);
    }

    /** @return array<string, array{int, array<string,mixed>, string}> */
    public static function resumeOutcomes(): array
    {
        return [
            // The create never landed: the reference is still free, so the SAME attempt may be
            // initialized -- true transport-timeout recovery.
            'reference not found is absent' => [
                404,
                ['status' => false, 'message' => 'Transaction reference not found'],
                'absent',
            ],
            // Already paid: never re-create, adopt the reference so settlement can attribute it.
            'success is adopted' => [200, ['status' => true, 'data' => ['status' => 'success']], 'adopt'],
            // Exists but unpaid. Its authorization_url is NOT recoverable (verify does not carry
            // access_code/authorization_url), so this attempt can never produce a checkout: it is
            // replaced. Safe because the interrupted call never returned a url to anyone.
            'abandoned is replaced' => [200, ['status' => true, 'data' => ['status' => 'abandoned']], 'replace'],
            'failed is replaced' => [200, ['status' => true, 'data' => ['status' => 'failed']], 'replace'],
            'reversed is replaced' => [200, ['status' => true, 'data' => ['status' => 'reversed']], 'replace'],
        ];
    }

    /**
     * @param array<string,mixed> $decoded
     * @dataProvider indeterminateResumeAnswers
     */
    public function testAnIndeterminateResumeAnswerThrowsRatherThanRiskingADuplicate(
        int $statusCode,
        array $decoded
    ): void {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf($statusCode, $decoded));
        $http->expects(self::never())->method('post');

        // Never "absent": guessing here would re-POST a reference that may well exist, which
        // Paystack refuses permanently. (Transport failures surface as HttpClientException, so
        // the shared expectation here is the Throwable contract, not one concrete class.)
        $this->expectException(\Throwable::class);
        $this->gateway($http)->resumeHostedSession($this->payable(), ['attempt_uuid' => 'atmpt1']);
    }

    /** @return array<string, array{int, array<string,mixed>}> */
    public static function indeterminateResumeAnswers(): array
    {
        return [
            'auth failure' => [401, ['status' => false, 'message' => 'Invalid key']],
            'rate limited' => [429, ['status' => false, 'message' => 'Too many requests']],
            'a 404 with an unfamiliar message' => [404, ['status' => false, 'message' => 'Something else']],
            'server error' => [500, []],
            'unrecognized transaction state' => [200, ['status' => true, 'data' => ['status' => 'quantum']]],
            'missing transaction data' => [200, ['status' => true]],
        ];
    }

    public function testAdoptingAPaidTransactionCarriesItsReferenceAndNoCheckoutUrl(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(200, [
            'status' => true,
            'data' => ['status' => 'success', 'reference' => 'commerce_order_ord1_atmpt1', 'amount' => 4999],
        ]));

        $resumed = $this->gateway($http)->resumeHostedSession($this->payable(), ['attempt_uuid' => 'atmpt1']);

        self::assertSame('adopt', $resumed['outcome']);
        self::assertSame('commerce_order_ord1_atmpt1', $resumed['session']['reference']);
        // Honest: Paystack's verify response cannot return the checkout url, and a paid
        // transaction needs none.
        self::assertNull($resumed['session']['checkout_url']);
    }

    public function testResumeWithoutAnAttemptUuidThrowsBeforeAnyRequest(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects(self::never())->method('get');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('attempt_uuid');
        $this->gateway($http)->resumeHostedSession($this->payable(), []);
    }

    public function testAnOverLongDerivedReferenceIsRefusedBeforeTheTransactionExists(): void
    {
        // security.nanoid_length is host-configurable, and payment_intents.reference is
        // varchar(100). Failing AFTER the Paystack transaction exists would strand the attempt
        // permanently, because the reference could never be reused.
        $http = $this->createMock(Client::class);
        $http->expects(self::never())->method('post');
        $http->expects(self::never())->method('get');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('over the 100-character limit');
        $this->gateway($http)->initialize($this->payable(), ['attempt_uuid' => str_repeat('a', 90)]);
    }
}
