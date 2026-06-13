<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\BaseController;
use Glueful\Extensions\Payvia\Services\InvoiceService;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class InvoiceController extends BaseController
{
    /** @var list<string> */
    private const STATUSES = ['draft', 'pending', 'paid', 'canceled', 'failed'];

    private const MAX_PER_PAGE = 100;

    public function __construct(
        ApplicationContext $context,
        private ?InvoiceService $invoices = null
    ) {
        parent::__construct($context);
        $this->invoices = $this->invoices ?? app($context, InvoiceService::class);
    }

    public function create(Request $request): Response
    {
        try {
            $data = $this->normalizeBody($request);

            $amount = $data['amount'] ?? null;

            $errors = [];
            if (!is_numeric($amount)) {
                $errors['amount'] = 'amount is required and must be numeric';
            } elseif ((float) $amount <= 0) {
                $errors['amount'] = 'amount must be greater than 0';
            }

            $currency = isset($data['currency']) && is_string($data['currency'])
                ? strtoupper(trim($data['currency']))
                : 'GHS';
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                $errors['currency'] = 'currency must be a 3-letter ISO code (e.g. GHS, USD)';
            }

            $status = isset($data['status']) && is_string($data['status']) ? $data['status'] : 'pending';
            if (!in_array($status, self::STATUSES, true)) {
                $errors['status'] = 'status must be one of: ' . implode(', ', self::STATUSES);
            }

            if ($errors !== []) {
                return $this->validationError($errors);
            }

            $payload = [
                'user_uuid' => isset($data['user_uuid']) && is_string($data['user_uuid']) ? $data['user_uuid'] : null,
                'billing_plan_uuid' => isset($data['billing_plan_uuid']) && is_string($data['billing_plan_uuid'])
                    ? $data['billing_plan_uuid']
                    : null,
                'payable_type' => isset($data['payable_type']) && is_string($data['payable_type'])
                    ? $data['payable_type']
                    : null,
                'payable_id' => isset($data['payable_id']) && is_string($data['payable_id'])
                    ? $data['payable_id']
                    : null,
                'number' => isset($data['number']) && is_string($data['number']) ? $data['number'] : null,
                'amount' => (float) $amount,
                'currency' => $currency,
                'status' => $status,
                'due_at' => isset($data['due_at']) && is_string($data['due_at']) ? $data['due_at'] : null,
                'metadata' => isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null,
            ];

            $uuid = $this->invoices->create($payload);

            return $this->created(['uuid' => $uuid], 'Invoice created');
        } catch (\Throwable $e) {
            $this->logError('invoice.create', $e);
            return $this->serverError('Failed to create invoice');
        }
    }

    public function markPaid(Request $request): Response
    {
        try {
            $data = $this->normalizeBody($request);

            $invoiceUuid = isset($data['invoice_uuid']) && is_string($data['invoice_uuid'])
                ? $data['invoice_uuid']
                : '';
            if ($invoiceUuid === '') {
                return $this->validationError(['invoice_uuid' => 'invoice_uuid is required']);
            }

            $paidAt = null;
            if (isset($data['paid_at']) && is_string($data['paid_at']) && $data['paid_at'] !== '') {
                try {
                    $paidAt = new \DateTimeImmutable($data['paid_at']);
                } catch (\Throwable) {
                    return $this->validationError([
                        'paid_at' => 'paid_at must be a valid datetime (e.g. Y-m-d H:i:s)',
                    ]);
                }
            }

            $ok = $this->invoices->markPaid($invoiceUuid, $paidAt);

            return $ok
                ? $this->success(['uuid' => $invoiceUuid], 'Invoice marked as paid')
                : $this->notFound('Invoice not found');
        } catch (\Throwable $e) {
            $this->logError('invoice.mark_paid', $e);
            return $this->serverError('Failed to mark invoice as paid');
        }
    }

    public function cancel(Request $request): Response
    {
        try {
            $data = $this->normalizeBody($request);

            $invoiceUuid = isset($data['invoice_uuid']) && is_string($data['invoice_uuid'])
                ? $data['invoice_uuid']
                : '';
            if ($invoiceUuid === '') {
                return $this->validationError(['invoice_uuid' => 'invoice_uuid is required']);
            }

            $ok = $this->invoices->markCanceled($invoiceUuid);

            return $ok
                ? $this->success(['uuid' => $invoiceUuid], 'Invoice canceled')
                : $this->notFound('Invoice not found');
        } catch (\Throwable $e) {
            $this->logError('invoice.cancel', $e);
            return $this->serverError('Failed to cancel invoice');
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
            if (
                isset($query['billing_plan_uuid'])
                && is_string($query['billing_plan_uuid'])
                && $query['billing_plan_uuid'] !== ''
            ) {
                $filters['billing_plan_uuid'] = $query['billing_plan_uuid'];
            }
            if (isset($query['payable_type']) && is_string($query['payable_type']) && $query['payable_type'] !== '') {
                $filters['payable_type'] = $query['payable_type'];
            }
            if (isset($query['payable_id']) && is_string($query['payable_id']) && $query['payable_id'] !== '') {
                $filters['payable_id'] = $query['payable_id'];
            }

            $metaKey = isset($query['metadata_key']) && is_string($query['metadata_key'])
                ? $query['metadata_key']
                : null;
            $metaValue = isset($query['metadata_value']) && is_string($query['metadata_value'])
                ? $query['metadata_value']
                : null;
            if ($metaKey !== null && $metaKey !== '' && $metaValue !== null && $metaValue !== '') {
                $filters['metadata_contains'] = [
                    'key' => $metaKey,
                    'value' => $metaValue,
                ];
            }

            $page = isset($query['page']) && is_numeric($query['page']) ? max(1, (int) $query['page']) : 1;
            $perPage = isset($query['per_page']) && is_numeric($query['per_page'])
                ? min(self::MAX_PER_PAGE, max(1, (int) $query['per_page']))
                : 20;

            $result = $this->invoices->list($page, $perPage, $filters);

            $data = $result['data'] ?? [];
            $meta = $result;
            unset($meta['data']);

            return Response::successWithMeta($data, $meta, 'Invoices retrieved');
        } catch (\Throwable $e) {
            $this->logError('invoice.index', $e);
            return $this->serverError('Failed to list invoices');
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
