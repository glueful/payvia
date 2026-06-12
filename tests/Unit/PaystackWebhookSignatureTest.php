<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Gateways\PaystackGateway;
use Glueful\Http\Client;
use Glueful\Http\Response\Response as HttpResponse;
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

    public function testVerifyIgnoresCallerSuppliedVerifyUrl(): void
    {
        $http = $this->createMock(Client::class);
        $response = $this->createMock(HttpResponse::class);

        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'status' => true,
            'data' => [
                'id' => 99,
                'reference' => 'REF_1',
                'status' => 'success',
                'amount' => 5000,
                'currency' => 'GHS',
            ],
        ]);

        // The verify URL must always be derived from the configured base_url, never from
        // the caller-supplied options. If the override were honored, the request would go to
        // https://attacker.example/forged instead of the asserted Paystack URL.
        $http->expects(self::once())
            ->method('get')
            ->with(
                'https://api.paystack.co/transaction/verify/REF_1',
                self::arrayHasKey('headers')
            )
            ->willReturn($response);

        $base = sys_get_temp_dir() . '/payvia-paystack-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents($base . '/config/payvia.php', "<?php\nreturn " . var_export([
            'gateways' => [
                'paystack' => [
                    'secret_key' => 'secret',
                    'base_url' => 'https://api.paystack.co',
                ],
            ],
        ], true) . ";\n");
        $context = new ApplicationContext($base, 'testing');
        $context->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));

        $result = (new PaystackGateway($http, $context))->verify('REF_1', [
            'verify_url' => 'https://attacker.example/forged',
        ]);

        self::assertSame('success', $result['status']);
        self::assertSame('REF_1', $result['reference']);
    }

    public function testCancelSubscriptionPostsCodeAndEmailToken(): void
    {
        $http = $this->createMock(Client::class);
        $fetchResponse = $this->createMock(HttpResponse::class);
        $cancelResponse = $this->createMock(HttpResponse::class);

        $fetchResponse->method('toArray')->willReturn([
            'data' => [
                'subscription_code' => 'SUB_1',
                'email_token' => 'TOKEN_1',
            ],
        ]);
        $cancelResponse->method('toArray')->willReturn(['status' => true]);

        $http->expects(self::once())
            ->method('get')
            ->with(
                'https://api.paystack.co/subscription/SUB_1',
                self::arrayHasKey('headers')
            )
            ->willReturn($fetchResponse);

        $http->expects(self::once())
            ->method('post')
            ->with(
                'https://api.paystack.co/subscription/disable',
                self::callback(static function (array $options): bool {
                    return ($options['json']['code'] ?? null) === 'SUB_1'
                        && ($options['json']['token'] ?? null) === 'TOKEN_1';
                })
            )
            ->willReturn($cancelResponse);

        $base = sys_get_temp_dir() . '/payvia-paystack-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents($base . '/config/payvia.php', "<?php\nreturn " . var_export([
            'gateways' => [
                'paystack' => [
                    'secret_key' => 'secret',
                    'base_url' => 'https://api.paystack.co',
                ],
            ],
        ], true) . ";\n");
        $context = new ApplicationContext($base, 'testing');
        $context->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));

        $result = (new PaystackGateway($http, $context))->cancelSubscription('SUB_1');

        self::assertSame(['status' => true], $result);
    }
}
