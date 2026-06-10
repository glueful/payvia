<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Gateways\PaystackGateway;
use Glueful\Http\Client;
use PHPUnit\Framework\TestCase;

final class PaystackWebhookSignatureTest extends TestCase
{
    private function gateway(string $secret = 'secret'): PaystackGateway
    {
        $base = sys_get_temp_dir() . '/payvia-paystack-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents($base . '/config/payvia.php', "<?php\nreturn " . var_export([
            'gateways' => [
                'paystack' => [
                    'webhook_secret' => $secret,
                    'secret_key' => $secret,
                ],
            ],
        ], true) . ";\n");
        $context = new ApplicationContext($base, 'testing');
        $context->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));

        return new PaystackGateway($this->createMock(Client::class), $context);
    }

    public function testValidHmacSha512SignaturePasses(): void
    {
        $body = '{"event":"charge.success","data":{"reference":"R1"}}';
        $signature = hash_hmac('sha512', $body, 'secret');

        self::assertTrue($this->gateway()->verifyWebhookSignature($body, [
            'x-paystack-signature' => $signature,
        ]));
    }

    public function testParseChargeSuccessNormalizesEvent(): void
    {
        $body = json_encode([
            'event' => 'charge.success',
            'data' => [
                'id' => 123,
                'reference' => 'R1',
                'status' => 'success',
                'amount' => 5000,
                'currency' => 'GHS',
            ],
        ], JSON_THROW_ON_ERROR);

        $event = $this->gateway()->parseWebhookEvent($body, []);

        self::assertSame(EventType::PAYMENT_SUCCEEDED, $event->type());
        self::assertSame('payment.succeeded:R1', $event->logicalEventKey());
        self::assertSame('R1', $event->normalized()['reference']);
        self::assertSame(50.0, $event->normalized()['amount']);
    }
}
