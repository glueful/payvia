<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\BaseController;
use Glueful\Extensions\Payvia\Services\PaymentService;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class PaymentController extends BaseController
{
    public function __construct(
        ApplicationContext $context,
        private ?PaymentService $payments = null
    ) {
        parent::__construct($context);
        $this->payments = $this->payments ?? app($context, PaymentService::class);
    }

    /**
     * Confirm a payment via a configured gateway and record it.
     */
    #[ApiOperation(
        summary: 'Confirm Payment via Gateway',
        description: 'Verifies a payment with a configured gateway (Paystack, Stripe, etc.) and upserts a '
            . 'record into the generic `payments` table. Body: `reference` (required; provider transaction '
            . 'reference), `gateway` (gateway key from `payvia.gateways` config, defaults to '
            . '`payvia.default_gateway`), `payable_type` (optional logical type for the payable, e.g. '
            . 'subscription, order), `payable_id` (optional identifier of the payable in its domain), '
            . '`metadata` (optional free-form JSON metadata to persist), `options` (optional gateway-specific '
            . 'options passed to the gateway driver). Requires authentication. The stored `user_uuid` is '
            . 'always derived from the authenticated session and is NOT caller-settable; supplying a '
            . '`user_uuid` that differs from the session returns 422.',
        tags: ['Payments'],
    )]
    #[ApiResponse(200, description: 'Payment verified and recorded')]
    #[ApiResponse(422, description: 'Validation failed (also returned if a user_uuid that differs from the '
        . 'authenticated session is supplied)')]
    public function confirm(Request $request): Response
    {
        try {
            $content = $request->getContent();
            $data = is_string($content) && $content !== '' ? json_decode($content, true) : [];
            if (!is_array($data)) {
                $data = [];
            }
            // Accept parameters from the JSON body and POST form fields only.
            // Query-string params are deliberately not read: payment references
            // and related parameters supplied via the URL would otherwise be
            // captured in access logs.
            $data = array_merge($request->request->all(), $data);

            $reference = isset($data['reference']) && is_string($data['reference']) ? $data['reference'] : '';
            if ($reference === '') {
                throw ValidationException::forField('reference', 'reference is required');
            }

            $gateway = isset($data['gateway']) && is_string($data['gateway']) ? $data['gateway'] : null;

            // Bind the payment to the authenticated session, never to a caller-supplied value.
            // $this->currentUser is populated by BaseController from the request's auth context.
            $authUuid = $this->currentUser?->uuid();

            // If the caller still sends user_uuid, it must match the session; otherwise reject
            // (do not silently overwrite, and never honor a value for a different user).
            if (isset($data['user_uuid']) && is_string($data['user_uuid']) && $data['user_uuid'] !== '') {
                if ($authUuid === null || $data['user_uuid'] !== $authUuid) {
                    return $this->validationError([
                        'user_uuid' => 'user_uuid is derived from the authenticated session and cannot be set',
                    ]);
                }
            }

            $context = [
                'user_uuid' => $authUuid,
                'payable_type' => isset($data['payable_type']) && is_string($data['payable_type'])
                    ? $data['payable_type']
                    : null,
                'payable_id' => isset($data['payable_id']) && is_string($data['payable_id'])
                    ? $data['payable_id']
                    : null,
                'metadata' => isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null,
                'options' => isset($data['options']) && is_array($data['options']) ? $data['options'] : [],
            ];

            $result = $this->payments->confirmAndRecord($reference, $gateway, $context);

            return $this->success($result, 'Payment verified');
        } catch (ValidationException $e) {
            return $this->validationError(['reference' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logError('payment.confirm', $e);
            return $this->serverError('Failed to verify payment');
        }
    }

    /**
     * Log a controller exception server-side without leaking details to the client.
     */
    private function logError(string $endpoint, \Throwable $e): void
    {
        $message = sprintf(
            '[Payvia] %s failed: %s: %s in %s:%d',
            $endpoint,
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        try {
            app($this->context, \Psr\Log\LoggerInterface::class)->error($message, ['exception' => $e]);
        } catch (\Throwable) {
            error_log($message);
        }
    }
}
