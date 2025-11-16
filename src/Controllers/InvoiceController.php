<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Controllers;

use Glueful\Controllers\BaseController;
use Glueful\Extensions\Payvia\Services\InvoiceService;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class InvoiceController extends BaseController
{
    public function __construct(
        private ?InvoiceService $invoices = null
    ) {
        parent::__construct();
        $this->invoices = $this->invoices ?? app(InvoiceService::class);
    }

    public function create(Request $request): Response
    {
        try {
            $data = $this->normalizeBody($request);

            $amount = $data['amount'] ?? null;
            if (!is_numeric($amount)) {
                return $this->validationError(['amount' => 'amount is required and must be numeric']);
            }

            $payload = [
                'user_uuid' => isset($data['user_uuid']) && is_string($data['user_uuid']) ? $data['user_uuid'] : null,
                'billing_plan_uuid' => isset($data['billing_plan_uuid']) && is_string($data['billing_plan_uuid']) ? $data['billing_plan_uuid'] : null,
                'payable_type' => isset($data['payable_type']) && is_string($data['payable_type']) ? $data['payable_type'] : null,
                'payable_id' => isset($data['payable_id']) && is_string($data['payable_id']) ? $data['payable_id'] : null,
                'number' => isset($data['number']) && is_string($data['number']) ? $data['number'] : null,
                'amount' => (float) $amount,
                'currency' => isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : 'GHS',
                'status' => isset($data['status']) && is_string($data['status']) ? $data['status'] : 'pending',
                'due_at' => isset($data['due_at']) && is_string($data['due_at']) ? $data['due_at'] : null,
                'metadata' => isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null,
            ];

            $uuid = $this->invoices->create($payload);

            return $this->created(['uuid' => $uuid], 'Invoice created');
        } catch (\Throwable $e) {
            return $this->serverError('Failed to create invoice: ' . $e->getMessage());
        }
    }

    public function markPaid(Request $request): Response
    {
        try {
            $data = $this->normalizeBody($request);

            $invoiceUuid = isset($data['invoice_uuid']) && is_string($data['invoice_uuid']) ? $data['invoice_uuid'] : '';
            if ($invoiceUuid === '') {
                return $this->validationError(['invoice_uuid' => 'invoice_uuid is required']);
            }

            $paidAt = null;
            if (isset($data['paid_at']) && is_string($data['paid_at']) && $data['paid_at'] !== '') {
                try {
                    $paidAt = new \DateTimeImmutable($data['paid_at']);
                } catch (\Throwable) {
                    // ignore parse error; fall back to now
                    $paidAt = null;
                }
            }

            $ok = $this->invoices->markPaid($invoiceUuid, $paidAt);

            return $ok
                ? $this->success(['uuid' => $invoiceUuid], 'Invoice marked as paid')
                : $this->notFound('Invoice not found');
        } catch (\Throwable $e) {
            return $this->serverError('Failed to mark invoice as paid: ' . $e->getMessage());
        }
    }

    public function cancel(Request $request): Response
    {
        try {
            $data = $this->normalizeBody($request);

            $invoiceUuid = isset($data['invoice_uuid']) && is_string($data['invoice_uuid']) ? $data['invoice_uuid'] : '';
            if ($invoiceUuid === '') {
                return $this->validationError(['invoice_uuid' => 'invoice_uuid is required']);
            }

            $ok = $this->invoices->markCanceled($invoiceUuid);

            return $ok
                ? $this->success(['uuid' => $invoiceUuid], 'Invoice canceled')
                : $this->notFound('Invoice not found');
        } catch (\Throwable $e) {
            return $this->serverError('Failed to cancel invoice: ' . $e->getMessage());
        }
    }

    public function index(Request $request): Response
    {
        try {
            $query = $request->query->all();

            $filters = [];
            if (isset($query['status']) && is_string($query['status']) && $query['status'] !== '') {
                $filters['status'] = $query['status'];
            }
            if (isset($query['user_uuid']) && is_string($query['user_uuid']) && $query['user_uuid'] !== '') {
                $filters['user_uuid'] = $query['user_uuid'];
            }
            if (isset($query['billing_plan_uuid']) && is_string($query['billing_plan_uuid']) && $query['billing_plan_uuid'] !== '') {
                $filters['billing_plan_uuid'] = $query['billing_plan_uuid'];
            }
            if (isset($query['payable_type']) && is_string($query['payable_type']) && $query['payable_type'] !== '') {
                $filters['payable_type'] = $query['payable_type'];
            }
            if (isset($query['payable_id']) && is_string($query['payable_id']) && $query['payable_id'] !== '') {
                $filters['payable_id'] = $query['payable_id'];
            }

            $metaKey = isset($query['metadata_key']) && is_string($query['metadata_key']) ? $query['metadata_key'] : null;
            $metaValue = isset($query['metadata_value']) && is_string($query['metadata_value']) ? $query['metadata_value'] : null;
            if ($metaKey !== null && $metaKey !== '' && $metaValue !== null && $metaValue !== '') {
                $filters['metadata_contains'] = [
                    'key' => $metaKey,
                    'value' => $metaValue,
                ];
            }

            $invoices = $this->invoices->list($filters);

            return $this->success(['invoices' => $invoices], 'Invoices retrieved');
        } catch (\Throwable $e) {
            return $this->serverError('Failed to list invoices: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeBody(Request $request): array
    {
        $content = $request->getContent();
        $data = is_string($content) && $content !== '' ? json_decode($content, true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        return array_merge($request->query->all(), $request->request->all(), $data);
    }
}
