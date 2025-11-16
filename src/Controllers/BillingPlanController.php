<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Controllers;

use Glueful\Controllers\BaseController;
use Glueful\Extensions\Payvia\Services\BillingPlanService;
use Glueful\Http\Response;
use Glueful\Exceptions\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class BillingPlanController extends BaseController
{
    public function __construct(
        private ?BillingPlanService $plans = null
    ) {
        parent::__construct();
        $this->plans = $this->plans ?? app(BillingPlanService::class);
    }

    public function create(Request $request): Response
    {
        try {
            $data = $this->normalizeBody($request);

            $name = isset($data['name']) && is_string($data['name']) ? trim($data['name']) : '';
            $amount = $data['amount'] ?? null;

            if ($name === '' || !is_numeric($amount)) {
                $errors = [];
                if ($name === '') {
                    $errors['name'] = 'name is required';
                }
                if (!is_numeric($amount)) {
                    $errors['amount'] = 'amount is required and must be numeric';
                }
                return $this->validationError($errors);
            }

            $payload = [
                'name' => $name,
                'description' => isset($data['description']) && is_string($data['description']) ? $data['description'] : null,
                'amount' => (float) $amount,
                'currency' => isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : 'GHS',
                'interval' => isset($data['interval']) && is_string($data['interval']) ? $data['interval'] : 'monthly',
                'trial_days' => isset($data['trial_days']) && is_numeric($data['trial_days']) ? (int) $data['trial_days'] : null,
                'features' => isset($data['features']) && is_array($data['features']) ? $data['features'] : null,
                'metadata' => isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null,
                'status' => isset($data['status']) && is_string($data['status']) ? $data['status'] : 'active',
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

            $update = [];
            if (isset($data['name']) && is_string($data['name'])) {
                $update['name'] = trim($data['name']);
            }
            if (array_key_exists('description', $data) && is_string($data['description'])) {
                $update['description'] = $data['description'];
            }
            if (array_key_exists('amount', $data) && is_numeric($data['amount'])) {
                $update['amount'] = (float) $data['amount'];
            }
            if (array_key_exists('currency', $data) && is_string($data['currency'])) {
                $update['currency'] = $data['currency'];
            }
            if (array_key_exists('interval', $data) && is_string($data['interval'])) {
                $update['interval'] = $data['interval'];
            }
            if (array_key_exists('trial_days', $data) && is_numeric($data['trial_days'])) {
                $update['trial_days'] = (int) $data['trial_days'];
            }
            if (array_key_exists('features', $data) && is_array($data['features'])) {
                $update['features'] = $data['features'];
            }
            if (array_key_exists('metadata', $data) && is_array($data['metadata'])) {
                $update['metadata'] = $data['metadata'];
            }
            if (array_key_exists('status', $data) && is_string($data['status'])) {
                $update['status'] = $data['status'];
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

            $featuresKey = isset($query['features_key']) && is_string($query['features_key']) ? $query['features_key'] : null;
            $featuresValue = isset($query['features_value']) && is_string($query['features_value']) ? $query['features_value'] : null;
            if ($featuresKey !== null && $featuresKey !== '' && $featuresValue !== null && $featuresValue !== '') {
                $filters['features_contains'] = [
                    'key' => $featuresKey,
                    'value' => $featuresValue,
                ];
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
}
