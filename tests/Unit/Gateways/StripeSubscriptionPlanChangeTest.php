<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit\Gateways;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\Payvia\Contracts\SubscriptionPlanChangeCapableGateway;
use Glueful\Extensions\Payvia\Gateways\PaystackGateway;
use Glueful\Extensions\Payvia\Gateways\StripeGateway;
use Glueful\Http\Client;
use Glueful\Http\Response\Response as HttpResponse;
use PHPUnit\Framework\TestCase;

/**
 * A live subscription could not move to another plan: no gateway offered it, so "Change plan"
 * was disabled everywhere. Stripe swaps the subscription item's price in place, prorated; a
 * gateway that cannot (Paystack) does not claim the capability.
 */
final class StripeSubscriptionPlanChangeTest extends TestCase
{
    private function context(): ApplicationContext
    {
        $base = sys_get_temp_dir() . '/payvia-stripe-planchange-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents($base . '/config/payvia.php', "<?php\nreturn " . var_export([
            'gateways' => ['stripe' => ['secret_key' => 'sk_test_123', 'base_url' => 'https://api.stripe.com']],
        ], true) . ";\n");
        $context = new ApplicationContext($base, 'testing');
        $context->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));
        return $context;
    }

    /** @param array<string,mixed> $decoded */
    private function response(array $decoded): HttpResponse
    {
        $response = $this->createMock(HttpResponse::class);
        $response->method('toArray')->willReturn($decoded);
        $response->method('getStatusCode')->willReturn(isset($decoded['error']) ? 400 : 200);
        return $response;
    }

    public function testStripeSwapsTheSubscriptionItemsPriceProrated(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects(self::once())->method('get')
            ->with('https://api.stripe.com/v1/subscriptions/sub_1', self::anything())
            ->willReturn($this->response([
                'id' => 'sub_1',
                'items' => ['data' => [['id' => 'si_9', 'price' => ['id' => 'price_old']]]],
            ]));
        $http->expects(self::once())->method('post')
            ->with(
                'https://api.stripe.com/v1/subscriptions/sub_1',
                self::callback(static fn (array $o): bool => ($o['form_params']['items'][0]['id'] ?? null) === 'si_9'
                    && ($o['form_params']['items'][0]['price'] ?? null) === 'price_new'
                    && ($o['form_params']['proration_behavior'] ?? null) === 'create_prorations'
                    && ($o['form_params']['cancel_at_period_end'] ?? null) === 'false'),
            )
            ->willReturn($this->response([
                'id' => 'sub_1',
                'items' => ['data' => [['id' => 'si_9', 'price' => ['id' => 'price_new']]]],
            ]));

        $gateway = new StripeGateway($http, $this->context());

        self::assertInstanceOf(SubscriptionPlanChangeCapableGateway::class, $gateway);
        self::assertSame(['status' => 'changed'], $gateway->changeSubscriptionPlan('sub_1', 'price_new'));
    }

    public function testARefusalFromStripeIsReportedNotThrown(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->response(['id' => 'sub_1', 'items' => ['data' => [['id' => 'si_9']]]]));
        $http->method('post')->willReturn($this->response(['error' => ['message' => 'No such price: price_x']]));

        $result = (new StripeGateway($http, $this->context()))->changeSubscriptionPlan('sub_1', 'price_x');

        self::assertSame('failed', $result['status']);
        self::assertStringContainsString('No such price', (string) $result['message']);
    }

    public function testPaystackDoesNotClaimIt(): void
    {
        self::assertFalse(is_subclass_of(PaystackGateway::class, SubscriptionPlanChangeCapableGateway::class));
    }
}
