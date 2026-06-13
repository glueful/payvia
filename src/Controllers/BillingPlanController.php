<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\BaseController;
use Glueful\Extensions\Payvia\Services\BillingPlanService;
use Glueful\Http\Response;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class BillingPlanController extends BaseController
{
    /** @var list<string> */
    private const STATUSES = ['active', 'inactive'];

    /** @var list<string> */
    private const INTERVALS = ['monthly', 'yearly', 'one_time'];

    public function __construct(
        ApplicationContext $context,
        private ?BillingPlanService $plans = null
    ) {
        parent::__construct($context);
        $this->plans = $this->plans ?? app($context, BillingPlanService::class);
    }

    public function create(Request $request): Response
    {
        try {
            $data = $this->normalizeBody($request);

            $name = isset($data['name']) && is_string($data['name']) ? trim($data['name']) : '';
            $amount = $data['amount'] ?? null;

            $errors = [];
            if ($name === '') {
                $errors['name'] = 'name is required';
            }
            if (!is_numeric($amount)) {
                $errors['amount'] = 'amount is required and must be numeric';
            } elseif ((float) $amount <= 0) {
                $errors['amount'] = 'amount must be greater than 0';
            }

            $currency = isset($data['currency']) && is_string($data['currency'])
                ? strtoupper(trim($data['currency']))
                : 'GHS';
            if (!$this->isValidCurrency($currency)) {
                $errors['currency'] = 'currency must be a 3-letter ISO code (e.g. GHS, USD)';
            }

            $interval = isset($data['interval']) && is_string($data['interval']) ? $data['interval'] : 'monthly';
            if (!in_array($interval, self::INTERVALS, true)) {
                $errors['interval'] = 'interval must be one of: ' . implode(', ', self::INTERVALS);
            }

            $status = isset($data['status']) && is_string($data['status']) ? $data['status'] : 'active';
            if (!in_array($status, self::STATUSES, true)) {
                $errors['status'] = 'status must be one of: ' . implode(', ', self::STATUSES);
            }

            if ($errors !== []) {
                return $this->validationError($errors);
            }

            $payload = [
                'name' => $name,
                'description' => isset($data['description']) && is_string($data['description'])
                    ? $data['description']
                    : null,
                'amount' => (float) $amount,
                'currency' => $currency,
                'interval' => $interval,
                'trial_days' => isset($data['trial_days']) && is_numeric($data['trial_days'])
                    ? (int) $data['trial_days']
                    : null,
                'gateway' => isset($data['gateway']) && is_string($data['gateway']) ? $data['gateway'] : null,
                'gateway_product_id' => isset($data['gateway_product_id']) && is_string($data['gateway_product_id'])
                    ? $data['gateway_product_id']
                    : null,
                'gateway_price_id' => isset($data['gateway_price_id']) && is_string($data['gateway_price_id'])
                    ? $data['gateway_price_id']
                    : null,
                'metadata' => isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null,
                'status' => $status,
            ];

            $uuid = $this->plans->create($payload);

            return $this->created(['uuid' => $uuid], 'Plan created');
        } catch (\Throwable $e) {
            return $this->serverError('Failed to create plan: ' . $e->getMessage());
        }
    }

    public function update(Request $request): Response
    {
        try {
            $data = $this->normalizeBody($request);

            $planUuid = isset($data['plan_uuid']) && is_string($data['plan_uuid']) ? trim($data['plan_uuid']) : '';
            if ($planUuid === '') {
                return $this->validationError(['plan_uuid' => 'plan_uuid is required']);
            }

            $errors = [];
            $update = [];
            if (isset($data['name']) && is_string($data['name'])) {
                $update['name'] = trim($data['name']);
            }
            if (array_key_exists('description', $data) && is_string($data['description'])) {
                $update['description'] = $data['description'];
            }
            if (array_key_exists('amount', $data) && is_numeric($data['amount'])) {
                if ((float) $data['amount'] <= 0) {
                    $errors['amount'] = 'amount must be greater than 0';
                } else {
                    $update['amount'] = (float) $data['amount'];
                }
            }
            if (array_key_exists('currency', $data) && is_string($data['currency'])) {
                $currency = strtoupper(trim($data['currency']));
                if (!$this->isValidCurrency($currency)) {
                    $errors['currency'] = 'currency must be a 3-letter ISO code (e.g. GHS, USD)';
                } else {
                    $update['currency'] = $currency;
                }
            }
            if (array_key_exists('interval', $data) && is_string($data['interval'])) {
                if (!in_array($data['interval'], self::INTERVALS, true)) {
                    $errors['interval'] = 'interval must be one of: ' . implode(', ', self::INTERVALS);
                } else {
                    $update['interval'] = $data['interval'];
                }
            }
            if (array_key_exists('trial_days', $data) && is_numeric($data['trial_days'])) {
                $update['trial_days'] = (int) $data['trial_days'];
            }
            foreach (['gateway', 'gateway_product_id', 'gateway_price_id'] as $key) {
                if (array_key_exists($key, $data) && (is_string($data[$key]) || $data[$key] === null)) {
                    $update[$key] = $data[$key];
                }
            }
            if (array_key_exists('metadata', $data) && is_array($data['metadata'])) {
                $update['metadata'] = $data['metadata'];
            }
            if (array_key_exists('status', $data) && is_string($data['status'])) {
                if (!in_array($data['status'], self::STATUSES, true)) {
                    $errors['status'] = 'status must be one of: ' . implode(', ', self::STATUSES);
                } else {
                    $update['status'] = $data['status'];
                }
            }

            if ($errors !== []) {
                return $this->validationError($errors);
            }

            if ($update === []) {
                return $this->validationError(['data' => 'No updatable fields provided']);
            }

            $ok = $this->plans->update($planUuid, $update);

            return $ok
                ? $this->success(['uuid' => $planUuid], 'Plan updated')
                : $this->notFound('Plan not found');
        } catch (ValidationException $e) {
            return $this->validationError(['plan' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to update plan: ' . $e->getMessage());
        }
    }

    public function disable(Request $request): Response
    {
        try {
            $data = $this->normalizeBody($request);

            $planUuid = isset($data['plan_uuid']) && is_string($data['plan_uuid']) ? trim($data['plan_uuid']) : '';
            if ($planUuid === '') {
                return $this->validationError(['plan_uuid' => 'plan_uuid is required']);
            }

            $ok = $this->plans->disable($planUuid);

            return $ok
                ? $this->success(['uuid' => $planUuid], 'Plan disabled')
                : $this->notFound('Plan not found');
        } catch (\Throwable $e) {
            return $this->serverError('Failed to disable plan: ' . $e->getMessage());
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
            if (isset($query['interval']) && is_string($query['interval']) && $query['interval'] !== '') {
                $filters['interval'] = $query['interval'];
            }
            if (isset($query['currency']) && is_string($query['currency']) && $query['currency'] !== '') {
                $filters['currency'] = $query['currency'];
            }

            $plans = $this->plans->list($filters);

            return $this->success(['plans' => $plans], 'Plans retrieved');
        } catch (\Throwable $e) {
            return $this->serverError('Failed to list plans: ' . $e->getMessage());
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

    private function isValidCurrency(string $currency): bool
    {
        return preg_match('/^[A-Z]{3}$/', $currency) === 1;
    }
}
