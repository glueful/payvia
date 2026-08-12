<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit\Gateways;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\Payvia\Contracts\HostedSessionRenewalCapableGateway;
use Glueful\Extensions\Payvia\Contracts\HostedSessionStateCapableGateway;
use Glueful\Extensions\Payvia\Gateways\StripeGateway;
use Glueful\Http\Client;
use Glueful\Http\Exceptions\HttpClientException;
use Glueful\Http\Response\Response as HttpResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ResponseInterface as SymfonyResponse;

/**
 * Stripe's hosted-session liveness surface (payment-links Task 2, spec §2.1 / Ruling 5).
 *
 * Two distinct questions, deliberately two methods:
 *
 *  - {@see StripeGateway::hostedSessionState()} — a READ-ONLY probe answering "is the session the
 *    collector already persisted still usable?" It never mutates provider state, so an ensure-live
 *    call that finds a live session can hand back the SAME url without touching Stripe's copy.
 *  - {@see StripeGateway::abandonHostedSession()} — the PROOF step: status -> expire -> re-fetch.
 *    Only a re-fetch that comes back genuinely `expired`/`canceled` returns `confirmed_dead`, and
 *    only `confirmed_dead` may ever free the old intent. A completed session, a still-open one, a
 *    transport failure, and an unparseable body must never free it.
 */
final class StripeHostedSessionLivenessTest extends TestCase
{
    private function gateway(Client $http, ?string $secret = 'sk_test_123'): StripeGateway
    {
        $base = sys_get_temp_dir() . '/payvia-stripe-liveness-' . uniqid('', true);
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

        return new StripeGateway($http, $context);
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

    public function testImplementsBothLivenessContracts(): void
    {
        $gateway = $this->gateway($this->createMock(Client::class));

        self::assertInstanceOf(HostedSessionStateCapableGateway::class, $gateway);
        self::assertInstanceOf(HostedSessionRenewalCapableGateway::class, $gateway);
    }

    /**
     * @param array<string,mixed> $session
     * @dataProvider sessionStates
     */
    public function testTheReadOnlyProbeMapsProviderStateOntoTheSharedEnum(array $session, string $expected): void
    {
        $http = $this->createMock(Client::class);
        $http->expects(self::once())->method('get')
            ->with('https://api.stripe.com/v1/checkout/sessions/cs_test_live')
            ->willReturn($this->responseOf(200, $session));
        // A read-only probe: nothing is expired, nothing is created.
        $http->expects(self::never())->method('post');

        self::assertSame($expected, $this->gateway($http)->hostedSessionState('cs_test_live'));
    }

    /** @return array<string, array{array<string,mixed>, string}> */
    public static function sessionStates(): array
    {
        return [
            'open session is live' => [['status' => 'open'], 'live'],
            'complete + paid is completed' => [
                ['status' => 'complete', 'payment_status' => 'paid'],
                'completed',
            ],
            'complete + no payment required is completed' => [
                ['status' => 'complete', 'payment_status' => 'no_payment_required'],
                'completed',
            ],
            // An async payment method completes the SESSION but settles later; the payment
            // outcome is still pending, so the session must never be treated as dead.
            'complete + unpaid is still live' => [
                ['status' => 'complete', 'payment_status' => 'unpaid'],
                'live',
            ],
            'expired session is dead' => [['status' => 'expired'], 'dead'],
            'canceled session is dead' => [['status' => 'canceled'], 'dead'],
            'error body is unknown' => [['error' => ['message' => 'No such session']], 'unknown'],
            'unrecognized status is unknown' => [['status' => 'quantum'], 'unknown'],
            'missing status is unknown' => [['id' => 'cs_test_live'], 'unknown'],
        ];
    }

    /**
     * @param array<string,mixed> $refetched
     * @dataProvider abandonOutcomes
     */
    public function testAbandonExpiresThenRefetchesAndOnlyConfirmedDeadFreesTheIntent(
        array $refetched,
        string $expected
    ): void {
        $http = $this->createMock(Client::class);
        $http->expects(self::once())->method('post')
            ->with('https://api.stripe.com/v1/checkout/sessions/cs_test_abandon/expire')
            // Stripe rejects expiring an already-terminal session; the expire response is
            // deliberately never the classifier -- the re-fetch below is.
            ->willReturn($this->responseOf(400, ['error' => ['message' => 'already expired']]));
        $http->expects(self::once())->method('get')
            ->with('https://api.stripe.com/v1/checkout/sessions/cs_test_abandon')
            ->willReturn($this->responseOf(200, $refetched));

        self::assertSame($expected, $this->gateway($http)->abandonHostedSession('cs_test_abandon'));
    }

    /** @return array<string, array{array<string,mixed>, string}> */
    public static function abandonOutcomes(): array
    {
        return [
            'expired re-fetch is confirmed dead' => [['status' => 'expired'], 'confirmed_dead'],
            'canceled re-fetch is confirmed dead' => [['status' => 'canceled'], 'confirmed_dead'],
            'still-open re-fetch never frees the intent' => [['status' => 'open'], 'still_live'],
            'completed re-fetch never frees the intent' => [
                ['status' => 'complete', 'payment_status' => 'paid'],
                'still_live',
            ],
            'unparseable re-fetch is unknown' => [['status' => 'quantum'], 'unknown'],
            'error re-fetch is unknown' => [['error' => ['message' => 'nope']], 'unknown'],
        ];
    }

    public function testATransportFailureOnTheRefetchThrowsRatherThanFreeingTheIntent(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('post')->willReturn($this->responseOf(200, ['id' => 'cs_test_x', 'status' => 'expired']));
        $http->method('get')->willReturn($this->responseOf(503, []));

        // Never a fabricated `confirmed_dead`: an unreachable Stripe is an unknown outcome and
        // the caller (the collector) must fail closed on it.
        $this->expectException(HttpClientException::class);
        $this->gateway($http)->abandonHostedSession('cs_test_x');
    }

    public function testATransportFailureOnTheProbeThrowsRatherThanReportingDead(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(500, []));

        $this->expectException(HttpClientException::class);
        $this->gateway($http)->hostedSessionState('cs_test_x');
    }

    public function testProbingWithoutASecretThrowsBeforeAnyRequest(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects(self::never())->method('get');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing Stripe secret key');
        $this->gateway($http, null)->hostedSessionState('cs_test_x');
    }

    public function testAbandoningWithoutASecretThrowsBeforeAnyRequest(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects(self::never())->method('post');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing Stripe secret key');
        $this->gateway($http, null)->abandonHostedSession('cs_test_x');
    }

    public function testAnEmptyReferenceIsNeverProbedAndNeverReportedDead(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects(self::never())->method('get');
        $http->expects(self::never())->method('post');

        $gateway = $this->gateway($http);
        self::assertSame('unknown', $gateway->hostedSessionState(''));
        self::assertSame('unknown', $gateway->abandonHostedSession(''));
    }
}
