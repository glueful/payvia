<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Gateways\StripeGateway;
use Glueful\Http\Client;
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
        self::assertSame(50.0, $event->normalized()['amount']);
        self::assertSame('USD', $event->normalized()['currency']);
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
    }
}
