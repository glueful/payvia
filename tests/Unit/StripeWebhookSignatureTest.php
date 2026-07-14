<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Gateways\StripeGateway;
use Glueful\Http\Client;
use Glueful\Http\Response\Response as HttpResponse;
use PHPUnit\Framework\TestCase;

final class StripeWebhookSignatureTest extends TestCase
{
    private function gateway(string $secret = 'whsec_test'): StripeGateway
    {
        $base = sys_get_temp_dir() . '/payvia-stripe-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents($base . '/config/payvia.php', "<?php\nreturn " . var_export([
            'gateways' => [
                'stripe' => [
                    'secret_key' => 'sk_test',
                    'webhook_secret' => $secret,
                    'webhook_tolerance' => 300,
                    'base_url' => 'https://api.stripe.com',
                    'timeout' => 15,
                ],
            ],
        ], true) . ";\n");
        $context = new ApplicationContext($base, 'testing');
        $context->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));

        return new StripeGateway($this->createMock(Client::class), $context);
    }

    public function testValidStripeSignaturePasses(): void
    {
        $body = '{"id":"evt_1","type":"payment_intent.succeeded"}';
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, 'whsec_test');

        self::assertTrue($this->gateway()->verifyWebhookSignature($body, [
            'Stripe-Signature' => 't=' . $timestamp . ',v1=' . $signature,
        ]));
    }

    public function testExpiredStripeSignatureFails(): void
    {
        $body = '{"id":"evt_1","type":"payment_intent.succeeded"}';
        $timestamp = time() - 600;
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, 'whsec_test');

        self::assertFalse($this->gateway()->verifyWebhookSignature($body, [
            'Stripe-Signature' => 't=' . $timestamp . ',v1=' . $signature,
        ]));
    }

    public function testPaymentIntentSucceededNormalizesEvent(): void
    {
        $body = json_encode([
            'id' => 'evt_1',
            'type' => 'payment_intent.succeeded',
            'created' => 1700000000,
            'data' => [
                'object' => [
                    'id' => 'pi_123',
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount_received' => 5000,
                    'currency' => 'usd',
                    'customer' => 'cus_123',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $event = $this->gateway()->parseWebhookEvent($body, []);

        self::assertSame('stripe', $event->gateway());
        self::assertSame(EventType::PAYMENT_SUCCEEDED, $event->type());
        self::assertSame('evt_1', $event->providerEventId());
        self::assertSame('evt_1', $event->deliveryKey());
        self::assertSame('payment.succeeded:pi_123', $event->logicalEventKey());
        self::assertSame('pi_123', $event->normalized()['reference']);
        self::assertSame('pi_123', $event->normalized()['gateway_transaction_id']);
        self::assertSame('cus_123', $event->normalized()['gateway_customer_id']);
        // Wire amounts are already minor units (Stripe sends 5000 = USD 50.00);
        // normalization must pass them through untouched, never divide by 100.
        self::assertSame(5000, $event->normalized()['amount']);
        self::assertSame('minor', $event->normalized()['amount_unit']);
        self::assertSame('USD', $event->normalized()['currency']);
    }

    public function testCheckoutSessionCompletedWebhookNormalizesIntegerAmount(): void
    {
        $body = json_encode([
            'id' => 'evt_cs_1',
            'type' => 'checkout.session.completed',
            'created' => 1700000000,
            'data' => [
                'object' => [
                    'id' => 'cs_123',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 7500,
                    'currency' => 'ghs',
                    'customer' => 'cus_456',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $event = $this->gateway()->parseWebhookEvent($body, []);

        self::assertSame(EventType::PAYMENT_SUCCEEDED, $event->type());
        self::assertSame('cs_123', $event->normalized()['reference']);
        self::assertSame(7500, $event->normalized()['amount']);
        self::assertSame('minor', $event->normalized()['amount_unit']);
        self::assertSame('GHS', $event->normalized()['currency']);
    }

    public function testVerifyPaymentIntentPassesThroughIntegerAmount(): void
    {
        $http = $this->createMock(Client::class);
        $response = $this->createMock(HttpResponse::class);
        $response->method('toArray')->willReturn([
            'id' => 'pi_123',
            'status' => 'succeeded',
            'amount_received' => 5000,
            'currency' => 'ghs',
        ]);
        $http->method('get')->willReturn($response);

        $result = (new StripeGateway($http, $this->contextWithStripeConfig()))->verify('pi_123');

        self::assertSame('success', $result['status']);
        self::assertSame(5000, $result['amount']);
        self::assertSame('GHS', $result['currency']);
    }

    public function testVerifyCheckoutSessionPassesThroughIntegerAmount(): void
    {
        $http = $this->createMock(Client::class);
        $response = $this->createMock(HttpResponse::class);
        $response->method('toArray')->willReturn([
            'id' => 'cs_123',
            'payment_intent' => 'pi_456',
            'payment_status' => 'paid',
            'amount_total' => 7500,
            'currency' => 'ghs',
        ]);
        $http->method('get')->willReturn($response);

        // No 'object'/'type' option is supplied; the cs_ prefix alone must route
        // verify() to the checkout-session normalizer.
        $result = (new StripeGateway($http, $this->contextWithStripeConfig()))->verify('cs_123');

        self::assertSame('success', $result['status']);
        self::assertSame(7500, $result['amount']);
        self::assertSame('GHS', $result['currency']);
    }

    public function testSubscriptionUpdatedPastDueNormalizesMutableEvent(): void
    {
        $body = json_encode([
            'id' => 'evt_sub_1',
            'type' => 'customer.subscription.updated',
            'created' => 1700000000,
            'data' => [
                'object' => [
                    'id' => 'sub_123',
                    'object' => 'subscription',
                    'status' => 'past_due',
                    'customer' => 'cus_123',
                    'current_period_start' => 1700000000,
                    'current_period_end' => 1702592000,
                    'cancel_at_period_end' => true,
                    'items' => [
                        'data' => [
                            ['price' => ['id' => 'price_123']],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $event = $this->gateway()->parseWebhookEvent($body, []);

        self::assertSame(EventType::SUBSCRIPTION_PAST_DUE, $event->type());
        self::assertStringStartsWith('subscription.past_due:sub_123:', $event->logicalEventKey());
        self::assertSame('sub_123', $event->normalized()['gateway_subscription_id']);
        self::assertSame('cus_123', $event->normalized()['gateway_customer_id']);
        self::assertSame('price_123', $event->normalized()['gateway_price_id']);
        self::assertSame('2023-11-14 22:13:20', $event->normalized()['current_period_start']);
        self::assertSame('2023-12-14 22:13:20', $event->normalized()['current_period_end']);
        self::assertTrue($event->normalized()['cancel_at_period_end']);
        // Subscription payloads carry no amount field; the amount_unit marker
        // must only appear when a numeric amount is actually present.
        self::assertArrayNotHasKey('amount_unit', $event->normalized());
    }

    private function contextWithStripeConfig(): ApplicationContext
    {
        $base = sys_get_temp_dir() . '/payvia-stripe-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents($base . '/config/payvia.php', "<?php\nreturn " . var_export([
            'gateways' => [
                'stripe' => [
                    'secret_key' => 'sk_test',
                    'base_url' => 'https://api.stripe.com',
                ],
            ],
        ], true) . ";\n");
        $context = new ApplicationContext($base, 'testing');
        $context->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));

        return $context;
    }
}
