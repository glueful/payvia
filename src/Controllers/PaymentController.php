<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Controllers;

use Glueful\Controllers\BaseController;
use Glueful\Extensions\Payvia\Services\PaymentService;
use Glueful\Http\Response;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class PaymentController extends BaseController
{
    public function __construct(
        private ?PaymentService $payments = null
    ) {
        parent::__construct();
        $this->payments = $this->payments ?? app($this->getContext(), PaymentService::class);
    }

    /**
     * Confirm a payment via a configured gateway and record it.
     */
    public function confirm(Request $request): Response
    {
        try {
            $content = $request->getContent();
            $data = is_string($content) && $content !== '' ? json_decode($content, true) : [];
            if (!is_array($data)) {
                $data = [];
            }
            $data = array_merge($request->query->all(), $request->request->all(), $data);

            $reference = isset($data['reference']) && is_string($data['reference']) ? $data['reference'] : '';
            if ($reference === '') {
                throw new ValidationException('reference is required');
            }

            $gateway = isset($data['gateway']) && is_string($data['gateway']) ? $data['gateway'] : null;

            $context = [
                'user_uuid' => isset($data['user_uuid']) && is_string($data['user_uuid']) ? $data['user_uuid'] : null,
                'payable_type' => isset($data['payable_type']) && is_string($data['payable_type']) ? $data['payable_type'] : null,
                'payable_id' => isset($data['payable_id']) && is_string($data['payable_id']) ? $data['payable_id'] : null,
                'metadata' => isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null,
                'options' => isset($data['options']) && is_array($data['options']) ? $data['options'] : [],
            ];

            $result = $this->payments->confirmAndRecord($reference, $gateway, $context);

            return $this->success($result, 'Payment verified');
        } catch (ValidationException $e) {
            return $this->validationError(['reference' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to verify payment: ' . $e->getMessage());
        }
    }
}
