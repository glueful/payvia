<?php

declare(strict_types=1);

use Glueful\Routing\Router;
use Glueful\Extensions\Payvia\Controllers\PaymentController;
use Glueful\Extensions\Payvia\Controllers\BillingPlanController;
use Glueful\Extensions\Payvia\Controllers\InvoiceController;

/** @var Router $router Router instance injected by RouteManifest::load() */

$router->group(['prefix' => '/payvia'], function (Router $router) {
    /**
     * @route POST /payvia/payments/confirm
     * @summary Confirm Payment via Gateway
     * @description
     *   Verifies a payment with a configured gateway (Paystack, Stripe, etc.)
     *   and upserts a record into the generic `payments` table.
     * @tag Payments
     * @requestBody
     *   reference:string="Provider transaction reference" {required=reference}
     *   gateway:string="Gateway key from payvia.gateways config (defaults to payvia.default_gateway)"
     *   user_uuid:string="Optional UUID of the paying user"
     *   payable_type:string="Optional logical type for the payable (e.g. subscription, order)"
     *   payable_id:string="Optional identifier of the payable in its domain"
     *   metadata:object="Optional free-form JSON metadata to persist"
     *   options:object="Optional gateway-specific options (e.g. override verify URL)"
     * @response 200 application/json "Payment verified and recorded"
     * @response 422 "Validation failed"
     */
    $router->post('/payments/confirm', [PaymentController::class, 'confirm'])
        ->middleware(['auth', 'rate_limit:60,60']);

    /**
     * @route POST /payvia/plans
     * @summary Create Billing Plan
     * @description Creates a generic billing plan record.
     * @tag Billing
     * @requestBody
     *   name:string="Plan name" {required=name}
     *   description:string="Optional description"
     *   amount:number="Unit price" {required=amount}
     *   currency:string="Currency code (e.g. GHS, USD)"
     *   interval:string="Billing interval: monthly|yearly|one_time"
     *   trial_days:int="Optional trial days"
     *   features:object="JSON feature flags or usage limits"
     *   metadata:object="Additional metadata for the plan"
     *   status:string="Plan status (active|inactive)"
     * @response 201 application/json "Plan created"
     * @response 422 "Validation failed"
     */
    $router->post('/plans', [BillingPlanController::class, 'create'])
        ->middleware(['auth', 'rate_limit:30,60']);

    /**
     * @route POST /payvia/plans/update
     * @summary Update Billing Plan
     * @description Updates an existing billing plan by UUID.
     * @tag Billing
     * @requestBody
     *   plan_uuid:string="Plan UUID to update" {required=plan_uuid}
     *   name:string="New name"
     *   description:string="New description"
     *   amount:number="New unit price"
     *   currency:string="New currency code"
     *   interval:string="New billing interval"
     *   trial_days:int="New trial days"
     *   features:object="New feature set"
     *   metadata:object="New metadata"
     *   status:string="New status"
     * @response 200 application/json "Plan updated"
     * @response 404 "Plan not found"
     */
    $router->post('/plans/update', [BillingPlanController::class, 'update'])
        ->middleware(['auth', 'rate_limit:30,60']);

    /**
     * @route POST /payvia/plans/disable
     * @summary Disable Billing Plan
     * @description Marks a billing plan as inactive, preventing new subscriptions.
     * @tag Billing
     * @requestBody
     *   plan_uuid:string="Plan UUID to disable" {required=plan_uuid}
     * @response 200 application/json "Plan disabled"
     * @response 404 "Plan not found"
     */
    $router->post('/plans/disable', [BillingPlanController::class, 'disable'])
        ->middleware(['auth', 'rate_limit:30,60']);

    /**
     * @route GET /payvia/plans
     * @summary List Billing Plans
     * @description Lists billing plans with optional filters, including JSON feature filters.
     * @tag Billing
     * @query
     *   status:string="Filter by plan status (e.g. active, inactive)"
     *   interval:string="Filter by billing interval"
     *   currency:string="Filter by currency code"
     *   features_key:string="JSON key under features to filter by"
     *   features_value:string="Value the features key must contain"
     * @response 200 application/json "Plans retrieved"
     */
    $router->get('/plans', [BillingPlanController::class, 'index'])
        ->middleware(['auth', 'rate_limit:60,60']);

    /**
     * @route POST /payvia/invoices
     * @summary Create Invoice
     * @description Creates a generic invoice that can be reconciled with payments.
     * @tag Billing
     * @requestBody
     *   amount:number="Invoice amount" {required=amount}
     *   currency:string="Currency code (e.g. GHS, USD)"
     *   user_uuid:string="Optional user UUID"
     *   billing_plan_uuid:string="Optional billing plan UUID"
     *   payable_type:string="Optional logical type of the payable (e.g. subscription, order)"
     *   payable_id:string="Optional identifier of the payable"
     *   number:string="Optional custom invoice number"
     *   due_at:string="Optional due date (Y-m-d H:i:s)"
     *   metadata:object="Additional metadata for the invoice"
     * @response 201 application/json "Invoice created"
     * @response 422 "Validation failed"
     */
    $router->post('/invoices', [InvoiceController::class, 'create'])
        ->middleware(['auth', 'rate_limit:60,60']);

    /**
     * @route POST /payvia/invoices/mark-paid
     * @summary Mark Invoice as Paid
     * @description Marks an invoice as paid and records paid_at timestamp.
     * @tag Billing
     * @requestBody
     *   invoice_uuid:string="Invoice UUID to mark as paid" {required=invoice_uuid}
     *   paid_at:string="Optional paid at datetime (Y-m-d H:i:s)"
     * @response 200 application/json "Invoice marked as paid"
     * @response 404 "Invoice not found"
     */
    $router->post('/invoices/mark-paid', [InvoiceController::class, 'markPaid'])
        ->middleware(['auth', 'rate_limit:60,60']);

    /**
     * @route POST /payvia/invoices/cancel
     * @summary Cancel Invoice
     * @description Marks an invoice as canceled.
     * @tag Billing
     * @requestBody
     *   invoice_uuid:string="Invoice UUID to cancel" {required=invoice_uuid}
     * @response 200 application/json "Invoice canceled"
     * @response 404 "Invoice not found"
     */
    $router->post('/invoices/cancel', [InvoiceController::class, 'cancel'])
        ->middleware(['auth', 'rate_limit:60,60']);

    /**
     * @route GET /payvia/invoices
     * @summary List Invoices
     * @description Lists invoices with optional filters, including JSON metadata filters.
     * @tag Billing
     * @query
     *   status:string="Filter by invoice status (draft,pending,paid,canceled,failed)"
     *   user_uuid:string="Filter by user UUID"
     *   billing_plan_uuid:string="Filter by billing plan UUID"
     *   payable_type:string="Filter by payable type"
     *   payable_id:string="Filter by payable id"
     *   metadata_key:string="JSON key under metadata to filter by"
     *   metadata_value:string="Value the metadata key must contain"
     * @response 200 application/json "Invoices retrieved"
     */
    $router->get('/invoices', [InvoiceController::class, 'index'])
        ->middleware(['auth', 'rate_limit:60,60']);
});
