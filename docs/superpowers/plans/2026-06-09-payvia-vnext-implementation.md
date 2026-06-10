# Payvia v-next Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Release `glueful/payvia` 1.0.0 with priced/gateway plan linkage, a normalized provider-event seam, two-key idempotent webhook ingestion with an outbox, and persisted provider subscriptions.

**Architecture:** Each provider webhook (and each on-demand `verify()`) is normalized into one immutable `ProviderEvent` VO carrying two idempotency keys -- `deliveryKey` (dedupes an exact provider redelivery) and `logicalEventKey` (dedupes a business fact across paths). The webhook pipeline persists every delivery in `provider_events` (unique `(gateway, delivery_key)` is the DB-enforced ingest boundary), applies idempotent side effects (payment/invoice/`gateway_subscriptions` upserts), then dispatches one `PaymentProviderEvent` on the PSR-14 bus gated on `logicalEventKey` via an outbox (`dispatch_status` `pending -> dispatching -> dispatched`, plus `dispatched_at`) whose intermediate `dispatching` state is an atomic claim (a conditional UPDATE: only the worker that flips `pending -> dispatching` emits) so concurrent deliveries dispatch exactly once and a crash between claim and emit is recovered by `payvia:relay-events`. Capability interfaces (`WebhookCapableGateway`, `SubscriptionCapableGateway`) keep verify-only drivers valid; Paystack implements all three.

**Tech Stack:** PHP 8.3+, Glueful framework (>=1.50.1), PHPUnit, Paystack

---

## Verified framework APIs (do NOT re-verify; checked against framework/src on 2026-06-09)

- **Schema alter (additive columns):** `$schema->alterTable('billing_plans', function ($table) { $table->string('gateway', 50)->nullable(); ... })` -- the callback `$table` is a `TableBuilder` in alteration mode; `string/integer/boolean/timestamp/json` + `->nullable()/->default()` inside the callback emit `ADD COLUMN` SQL (`TableBuilder::executeAlterations()`, `src/Database/Schema/Builders/TableBuilder.php:799`). `alterTable($name, $callback)` auto-executes (`SchemaBuilder.php:122`). Idempotency guards: `$schema->hasTable($t)`, `$schema->hasColumn($t,$c)` (`SchemaBuilderInterface.php:140,149`).
- **Query builder** (`db($ctx)->table($t)` -> `QueryBuilder`): `where($col,$op,$val)` / `where(['col'=>$v])`, `whereIn`, `whereNull`, `first(): ?array`, `get(): array`, `insert(array): int`, `update(array): int`, `upsert(array $data, array $updateColumns): int`, `transaction(callable): mixed`. **SQLite upsert is `INSERT OR REPLACE` (replaces ALL columns)** -- so for `provider_events` we use find-then-insert/update + the DB unique constraint as the race boundary (NOT `upsert`), matching the existing `PaymentRepository` pattern.
- **Events:** `Glueful\Events\Contracts\BaseEvent` -- abstract, `__construct()` sets id/timestamp; subclasses call `parent::__construct()`. Dispatch via `Glueful\Events\EventService::dispatch(object $event): object` (resolve from container).
- **HTTP:** `Glueful\Http\Client::get/post(string $url, array $options): Response`; response `->getStatusCode(): int`, `->toArray(): array` (already used by `PaystackGateway`).
- **Queue:** `Glueful\Queue\QueueManager::push(string $jobClass, array $data = [], ?string $queue = null, ?string $connection = null): string`. Jobs implement `Glueful\Queue\Contracts\JobInterface`.
- **Migrations:** `Glueful\Database\Migrations\MigrationInterface` (`up(SchemaBuilderInterface)`, `down(...)`, `getDescription(): string`); priority `Glueful\Database\Migrations\MigrationPriority::DEPENDENT`; registered via `loadMigrationsFrom(dir, MigrationPriority::DEPENDENT, 'glueful/payvia')`.
- **Controller/Response:** `BaseController` helpers: `success($data,$msg)`, `created(...)`, `validationError(array)`, `notFound($msg)`, `serverError($msg)`. For webhook custom status codes use `Glueful\Http\Response::error(string $message, int $status, mixed $details = null)` (supports 401/404/5xx via `Response::HTTP_*`).
- **Console:** commands use `#[AsCommand(name: '...', description: '...')]` extending `Glueful\Console\BaseCommand`; auto-discovered in `boot()` via `$this->discoverCommands('Glueful\\Extensions\\Payvia\\Console', __DIR__ . '/Console')`.
- **UUIDs:** `Glueful\Helpers\Utils::generateNanoID(12)` (12-char), matching existing payvia repos.
- **Test harness:** lightweight in-memory SQLite `Connection(['engine'=>'sqlite','sqlite'=>['primary'=>':memory:'],'pooling'=>['enabled'=>false]])`, run migration `up($conn->getSchemaBuilder())`, wrap in a PSR-11 container exposing `'database'` + `Connection::class` on a real `ApplicationContext` -- mirrors `tenancy/tests/Support/TenancyTestCase.php`.

**No missing-framework-API blockers were found.** Every API the spec needs (`alterTable`, `BaseEvent`, `EventService`, `Http\Client`, `QueueManager`, `MigrationPriority`, `Response::error`) exists at the current floor; framework floor stays `>=1.50.1` (D11).

---

## File Structure

### New source files
| Path | Responsibility |
|---|---|
| `src/Contracts/PaymentProviderEventInterface.php` | Normalized provider-event contract (W3): `gateway/type/providerEventId/deliveryKey/logicalEventKey/occurredAt/normalized/raw`. |
| `src/Contracts/WebhookCapableGateway.php` | Capability interface: `verifyWebhookSignature`, `parseWebhookEvent`. |
| `src/Contracts/SubscriptionCapableGateway.php` | Capability interface: `fetchSubscription`, `cancelSubscription`. |
| `src/Contracts/ProviderEventRepositoryInterface.php` | `provider_events` persistence + status/outbox transitions. |
| `src/Contracts/GatewaySubscriptionRepositoryInterface.php` | `gateway_subscriptions` persistence/read. |
| `src/Events/ProviderEvent.php` | Immutable VO implementing `PaymentProviderEventInterface`; derives `logicalEventKey`. |
| `src/Events/PaymentProviderEvent.php` | `BaseEvent` carrying a `PaymentProviderEventInterface` (D5). |
| `src/Events/EventType.php` | Normalized type vocabulary constants + `isImmutable()`/`isKnown()`. |
| `src/Repositories/ProviderEventRepository.php` | `provider_events` impl (find-then-insert/update; status + outbox). |
| `src/Repositories/GatewaySubscriptionRepository.php` | `gateway_subscriptions` impl. |
| `src/Services/WebhookService.php` | Ingest -> apply -> dispatch pipeline; sync/queued; verify-origin events (D3,D6). |
| `src/Services/GatewaySubscriptionService.php` | Subscription upserts + `reconcile()` (D8). |
| `src/Jobs/ProcessWebhookJob.php` | Queued processing of a persisted `received` event. |
| `src/Console/RelayEventsCommand.php` | `payvia:relay-events` outbox relay. |
| `src/Controllers/WebhookController.php` | `POST /payvia/webhooks/{gateway}` -- signature-verified, no auth. |
| `migrations/004_AddGatewayLinkageToBillingPlans.php` | Additive nullable `gateway`/`gateway_product_id`/`gateway_price_id` (W1). |
| `migrations/005_CreateProviderEventsTable.php` | Two-key, status-aware, outbox table (W2). |
| `migrations/006_CreateGatewaySubscriptionsTable.php` | Persisted provider subscriptions (W4). |

### Modified source files
| Path | Change |
|---|---|
| `src/Gateways/PaystackGateway.php` | Implement `WebhookCapableGateway` in Task 3.5 (HMAC SHA512 verify, synthetic delivery key, event mapping), then add `SubscriptionCapableGateway` + `fetchSubscription`/`cancelSubscription` in Task 4.4 (interface added with its methods, never before). |
| `src/GatewayManager.php` | Add `supports(gateway, capability)`, `webhookGateway()`, `subscriptionGateway()`. |
| `src/Services/PaymentService.php` | Route `verify()` success/failure through the `provider_events` outbox (D6). |
| `src/Repositories/BillingPlanRepository.php` | Add `gateway`/`gateway_product_id`/`gateway_price_id` to `create`/`list` select; remove billing-plan entitlement fields. |
| `src/Controllers/BillingPlanController.php` | Whitelist + validate `gateway`/`gateway_product_id`/`gateway_price_id` on create/update so they can be set via the API. |
| `src/PayviaServiceProvider.php` | Register new repos/services/controllers; discover `Console/`. |
| `routes.php` | Add `POST /payvia/webhooks/{gateway}`; drop `features` from plan-route docblocks. |
| `config/payvia.php` | Add `webhooks.queue` + `gateways.paystack.webhook_secret`. |
| `composer.json` | Bump `extra.glueful.version` to `1.0.0`; keep floor `>=1.50.1`. |
| `README.md` | Webhooks/events/subscriptions docs; remove `features` from plan examples. |
| `CHANGELOG.md` | `[1.0.0]` entry. |

### New test files
| Path | Covers |
|---|---|
| `tests/Support/PayviaTestCase.php` | In-memory SQLite harness + container + `ApplicationContext`. |
| `tests/Support/FakeWebhookGateway.php` | In-suite fake implementing both capability interfaces. |
| `tests/Unit/Events/EventTypeTest.php` | Vocabulary + `isImmutable`. |
| `tests/Unit/Events/ProviderEventTest.php` | `logicalEventKey` derivation (immutable vs mutable). |
| `tests/Unit/PaystackWebhookSignatureTest.php` | HMAC SHA512 verify + synthetic delivery key. |
| `tests/Integration/MigrationsTest.php` | All 3 v-next migrations run + columns exist. |
| `tests/Integration/BillingPlanApiTest.php` | Gateway-linkage fields round-trip through plan create/update controller. |
| `tests/Integration/ProviderEventRepositoryTest.php` | Status transitions + outbox + unique race. |
| `tests/Integration/WebhookIngestionTest.php` | Pipeline: new/duplicate/cross-path/crash-replay/queued-reconstruct. |
| `tests/Integration/RelayEventsTest.php` | Outbox relay + atomic-claim concurrency + crash-during-dispatch recovery. |
| `tests/Integration/GatewaySubscriptionServiceTest.php` | Webhook upsert + `reconcile()`. |
| `tests/Integration/GatewayManagerCapabilityTest.php` | `supports`/typed resolvers. |
| `tests/Integration/PaymentServiceOutboxTest.php` | verify-origin event deduped with webhook (D6). |

### New infra files
| Path | Responsibility |
|---|---|
| `phpunit.xml` | PHPUnit config (testsuite `tests`, bootstrap autoload). |

---

## Phase 0 -- Test harness scaffolding

### Task 0.1 -- PHPUnit config + base test case

**Files:**
- Create: `phpunit.xml`
- Create: `tests/Support/PayviaTestCase.php`
- Modify: `composer.json` (add `glueful/queue` not needed; ensure `autoload-dev` already maps `Tests\` -- it does)

**Steps:**
- [ ] Create `phpunit.xml`:
  ```xml
  <?xml version="1.0" encoding="UTF-8"?>
  <phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
           bootstrap="vendor/autoload.php"
           colors="true"
           cacheDirectory=".phpunit.cache">
      <testsuites>
          <testsuite name="Payvia">
              <directory>tests</directory>
          </testsuite>
      </testsuites>
      <source>
          <include>
              <directory>src</directory>
          </include>
      </source>
  </phpunit>
  ```
- [ ] Create `tests/Support/PayviaTestCase.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Tests\Support;

  use Glueful\Bootstrap\ApplicationContext;
  use Glueful\Database\Connection;
  use PHPUnit\Framework\TestCase;
  use Psr\Container\ContainerInterface;

  /**
   * In-memory-SQLite harness for payvia DB-backed tests.
   *
   * Builds a bare Connection against :memory:, exposes a tiny PSR-11 container that
   * resolves 'database' and Connection::class to the one harness connection on a real
   * ApplicationContext, and lets subclasses register extra container bindings (e.g. a
   * fake gateway). Migrations are run by the test that needs them via runMigration().
   */
  abstract class PayviaTestCase extends TestCase
  {
      protected ApplicationContext $context;
      protected Connection $connection;
      /** @var array<string,mixed> */
      protected array $bindings = [];

      protected function setUp(): void
      {
          parent::setUp();

          $this->connection = new Connection([
              'engine' => 'sqlite',
              'sqlite' => ['primary' => ':memory:'],
              'pooling' => ['enabled' => false],
          ]);

          $connection = $this->connection;
          $bindings = &$this->bindings;
          $container = new class ($connection, $bindings) implements ContainerInterface {
              /** @param array<string,mixed> $bindings */
              public function __construct(private Connection $connection, private array &$bindings)
              {
              }

              public function get(string $id): mixed
              {
                  if ($id === 'database' || $id === Connection::class) {
                      return $this->connection;
                  }
                  if (array_key_exists($id, $this->bindings)) {
                      return $this->bindings[$id];
                  }
                  throw new \RuntimeException("Unknown service: {$id}");
              }

              public function has(string $id): bool
              {
                  return $id === 'database'
                      || $id === Connection::class
                      || array_key_exists($id, $this->bindings);
              }
          };

          $this->context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
          $this->context->setContainer($container);
      }

      protected function bind(string $id, mixed $service): void
      {
          $this->bindings[$id] = $service;
      }

      protected function runMigration(object $migration): void
      {
          $migration->up($this->connection->getSchemaBuilder());
      }
  }
  ```
- [ ] Commit: `git add phpunit.xml tests/Support/PayviaTestCase.php && git commit -m "test: add in-memory SQLite harness for payvia"`

---

## Phase 1 -- Priced-plan reframe + entitlement-field removal (D1, D2)

### Task 1.1 -- Migration: add gateway-linkage columns to `billing_plans`

**Files:**
- Create: `migrations/004_AddGatewayLinkageToBillingPlans.php`
- Test: `tests/Integration/MigrationsTest.php`

**Steps:**
- [ ] Write failing test `tests/Integration/MigrationsTest.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Tests\Integration;

  use Glueful\Extensions\Payvia\Database\Migrations\AddGatewayLinkageToBillingPlans;
  use Glueful\Extensions\Payvia\Database\Migrations\CreateBillingPlansTable;
  use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

  final class MigrationsTest extends PayviaTestCase
  {
      public function testBillingPlansGainsNullableGatewayLinkageColumns(): void
      {
          $schema = $this->connection->getSchemaBuilder();
          (new CreateBillingPlansTable())->up($schema);
          (new AddGatewayLinkageToBillingPlans())->up($schema);

          self::assertTrue($schema->hasColumn('billing_plans', 'gateway'));
          self::assertTrue($schema->hasColumn('billing_plans', 'gateway_product_id'));
          self::assertTrue($schema->hasColumn('billing_plans', 'gateway_price_id'));
      }
  }
  ```
- [ ] Run (expect FAIL -- class not found): `vendor/bin/phpunit --filter=testBillingPlansGainsNullableGatewayLinkageColumns`
- [ ] Create `migrations/004_AddGatewayLinkageToBillingPlans.php`:
  ```php
  <?php

  namespace Glueful\Extensions\Payvia\Database\Migrations;

  use Glueful\Database\Migrations\MigrationInterface;
  use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

  /**
   * Reframe billing_plans as priced/gateway plans: add nullable gateway-linkage columns.
   *
   * Additive only (D2). All three columns are nullable; null = not yet linked / app-managed.
   * For Paystack only gateway_price_id is populated (plan code); gateway_product_id is
   * reserved for Stripe-shaped product/price splits.
   */
  class AddGatewayLinkageToBillingPlans implements MigrationInterface
  {
      public function up(SchemaBuilderInterface $schema): void
      {
          if (!$schema->hasColumn('billing_plans', 'gateway')) {
              $schema->alterTable('billing_plans', function ($table) {
                  $table->string('gateway', 50)->nullable();
              });
          }
          if (!$schema->hasColumn('billing_plans', 'gateway_product_id')) {
              $schema->alterTable('billing_plans', function ($table) {
                  $table->string('gateway_product_id', 100)->nullable();
              });
          }
          if (!$schema->hasColumn('billing_plans', 'gateway_price_id')) {
              $schema->alterTable('billing_plans', function ($table) {
                  $table->string('gateway_price_id', 100)->nullable();
              });
          }
      }

      public function down(SchemaBuilderInterface $schema): void
      {
          foreach (['gateway_price_id', 'gateway_product_id', 'gateway'] as $col) {
              if ($schema->hasColumn('billing_plans', $col)) {
                  $schema->alterTable('billing_plans')->dropColumn($col)->execute();
              }
          }
      }

      public function getDescription(): string
      {
          return 'Adds nullable gateway/gateway_product_id/gateway_price_id linkage columns to billing_plans.';
      }
  }
  ```
  > NOTE for implementer: `down()` uses the no-callback `alterTable('billing_plans')` fluent form (`AlterTableBuilderInterface::dropColumn()->execute()`, verified at `src/Database/Schema/Interfaces/AlterTableBuilderInterface.php`). Columns are dropped in reverse dependency order.
- [ ] Run (expect PASS): `vendor/bin/phpunit --filter=testBillingPlansGainsNullableGatewayLinkageColumns`
- [ ] Commit: `git add migrations/004_AddGatewayLinkageToBillingPlans.php tests/Integration/MigrationsTest.php && git commit -m "feat(plans): add nullable gateway-linkage columns to billing_plans"`

### Task 1.2 -- Repository + controller: accept/return gateway-linkage fields; remove entitlement fields

**Files:**
- Modify: `src/Repositories/BillingPlanRepository.php` (`list()` select array, lines 56-70)
- Modify: `src/Controllers/BillingPlanController.php` (whitelist + validate the three new fields on create/update)
- Test: `tests/Integration/MigrationsTest.php` (add a repo case)
- Test: `tests/Integration/BillingPlanApiTest.php` (round-trip the three fields through create + update)

**Steps:**
- [ ] Add failing test method to `MigrationsTest`:
  ```php
      public function testListReturnsGatewayLinkage(): void
      {
          $schema = $this->connection->getSchemaBuilder();
          (new CreateBillingPlansTable())->up($schema);
          (new AddGatewayLinkageToBillingPlans())->up($schema);

          $repo = new \Glueful\Extensions\Payvia\Repositories\BillingPlanRepository($this->connection);
          $repo->create([
              'name' => 'Pro',
              'amount' => 50.0,
              'currency' => 'GHS',
              'interval' => 'monthly',
              'gateway' => 'paystack',
              'gateway_price_id' => 'PLN_x',
              'status' => 'active',
          ]);

          $rows = $repo->list([]);
          self::assertSame('paystack', $rows[0]['gateway']);
          self::assertSame('PLN_x', $rows[0]['gateway_price_id']);
          self::assertArrayNotHasKey('features', $rows[0]);
      }
  ```
  > NOTE: `BaseRepository::__construct` takes the `Connection`; pass `$this->connection` directly (matches harness binding). If `BaseRepository` resolves the connection from a context instead, construct via the container helper -- verify the actual `BaseRepository` constructor signature when implementing and adjust this one line.
- [ ] Run (expect FAIL -- `gateway`/`gateway_price_id` not in select): `vendor/bin/phpunit --filter=testListReturnsGatewayLinkage`
- [ ] Edit `src/Repositories/BillingPlanRepository.php` `list()` select array to add the three columns and omit entitlement fields:
  ```php
              ->select([
                  'uuid',
                  'name',
                  'description',
                  'amount',
                  'currency',
                  'interval',
                  'trial_days',
                  'gateway',
                  'gateway_product_id',
                  'gateway_price_id',
                  'metadata',
                  'status',
                  'created_at',
                  'updated_at',
              ])
  ```
- [ ] Run (expect PASS): `vendor/bin/phpunit --filter=testListReturnsGatewayLinkage`
- [ ] Write failing API round-trip test `tests/Integration/BillingPlanApiTest.php` (proves the three fields are accepted by the controller and persisted, then returned on the update route):
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Tests\Integration;

  use Glueful\Extensions\Payvia\Controllers\BillingPlanController;
  use Glueful\Extensions\Payvia\Database\Migrations\AddGatewayLinkageToBillingPlans;
  use Glueful\Extensions\Payvia\Database\Migrations\CreateBillingPlansTable;
  use Glueful\Extensions\Payvia\Repositories\BillingPlanRepository;
  use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;
  use Symfony\Component\HttpFoundation\Request;

  final class BillingPlanApiTest extends PayviaTestCase
  {
      private BillingPlanController $controller;
      private BillingPlanRepository $repo;

      protected function setUp(): void
      {
          parent::setUp();
          $schema = $this->connection->getSchemaBuilder();
          (new CreateBillingPlansTable())->up($schema);
          (new AddGatewayLinkageToBillingPlans())->up($schema);

          $this->repo = new BillingPlanRepository($this->connection);
          // BillingPlanController takes (ApplicationContext, BillingPlanRepository|service).
          // Construct with the harness connection-backed repo so create/update hit :memory:.
          $this->controller = new BillingPlanController($this->context, $this->repo);
      }

      /** @param array<string,mixed> $body */
      private function jsonRequest(array $body): Request
      {
          return new Request([], [], [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
      }

      public function testCreatePersistsGatewayLinkageFields(): void
      {
          $request = $this->jsonRequest([
              'name' => 'Pro',
              'amount' => 50.0,
              'currency' => 'GHS',
              'interval' => 'monthly',
              'gateway' => 'paystack',
              'gateway_product_id' => 'PROD_1',
              'gateway_price_id' => 'PLN_x',
          ]);

          $this->controller->create($request);

          $rows = $this->repo->list([]);
          self::assertSame('paystack', $rows[0]['gateway']);
          self::assertSame('PROD_1', $rows[0]['gateway_product_id']);
          self::assertSame('PLN_x', $rows[0]['gateway_price_id']);
      }

      public function testUpdateChangesGatewayLinkageFields(): void
      {
          $uuid = $this->repo->create([
              'name' => 'Basic',
              'amount' => 10.0,
              'currency' => 'GHS',
              'interval' => 'monthly',
              'status' => 'active',
          ]);

          $request = $this->jsonRequest([
              'uuid' => $uuid,
              'gateway' => 'paystack',
              'gateway_price_id' => 'PLN_y',
          ]);
          $this->controller->update($request);

          $row = $this->repo->find($uuid);
          self::assertSame('paystack', $row['gateway']);
          self::assertSame('PLN_y', $row['gateway_price_id']);
      }
  }
  ```
  > NOTE for implementer: align the `BillingPlanController` constructor and the create/update method names + request-reading style with the existing controller (it may resolve a `BillingPlanService` rather than the repo directly, and read input via `$this->getRequestData()` / a DTO instead of raw `Request`). Adjust only the construction + request-building lines to match the real controller; the assertions (the three fields round-trip) are the fixed contract. If create/update return the new plan in the response envelope, assert on that instead of re-reading via the repo.
- [ ] Run (expect FAIL -- controller strips the unknown fields): `vendor/bin/phpunit --filter=BillingPlanApiTest`
- [ ] Edit `src/Controllers/BillingPlanController.php`: add `gateway`, `gateway_product_id`, `gateway_price_id` to the accepted/whitelisted payload AND to validation in BOTH `create()` and `update()`. All three are optional, nullable strings:
  ```php
          // In the create/update validation rule set (alongside the existing fields):
          'gateway'            => ['nullable', 'string', 'max:50'],
          'gateway_product_id' => ['nullable', 'string', 'max:100'],
          'gateway_price_id'   => ['nullable', 'string', 'max:100'],
  ```
  ```php
          // Where the controller assembles the data array passed to the repository/service,
          // include the three fields so they are persisted instead of silently dropped:
          $data = [
              // ... existing whitelisted fields (name, amount, currency, interval, metadata, ...) ...
              'gateway'            => $input['gateway'] ?? null,
              'gateway_product_id' => $input['gateway_product_id'] ?? null,
              'gateway_price_id'   => $input['gateway_price_id'] ?? null,
          ];
  ```
  > NOTE for implementer: match the controller's existing validation mechanism (the framework `Validator`/`Rule` objects or a DTO factory) and its existing whitelist pattern -- add the three keys to whatever list/DTO already governs create/update. For `update()`, only overwrite a column when the field is present in the request (use `array_key_exists` so an omitted field is left unchanged); all three remain nullable so they can be cleared explicitly.
- [ ] Run (expect PASS): `vendor/bin/phpunit --filter=BillingPlanApiTest`
- [ ] Commit: `git add src/Repositories/BillingPlanRepository.php src/Controllers/BillingPlanController.php tests/Integration/MigrationsTest.php tests/Integration/BillingPlanApiTest.php && git commit -m "feat(plans): expose gateway-linkage columns via API + repo"`

### Task 1.3 -- Remove entitlement filters from route docblocks

**Files:**
- Modify: `routes.php` (plan create/update/list docblocks)

**Steps:**
- [ ] In `routes.php`, remove any `features` request fields and `features_key`/`features_value` filters from plan route docblocks.
- [ ] Commit: `git add routes.php && git commit -m "docs(plans): remove entitlement fields from payvia route docs"`

---

## Phase 2 -- Normalized event seam (D5, D6)

### Task 2.1 -- Event-type vocabulary

**Files:**
- Create: `src/Events/EventType.php`
- Test: `tests/Unit/Events/EventTypeTest.php`

**Steps:**
- [ ] Write failing test `tests/Unit/Events/EventTypeTest.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Tests\Unit\Events;

  use Glueful\Extensions\Payvia\Events\EventType;
  use PHPUnit\Framework\TestCase;

  final class EventTypeTest extends TestCase
  {
      public function testImmutableTypesAreImmutable(): void
      {
          self::assertTrue(EventType::isImmutable(EventType::PAYMENT_SUCCEEDED));
          self::assertTrue(EventType::isImmutable(EventType::INVOICE_PAID));
          self::assertTrue(EventType::isImmutable(EventType::SUBSCRIPTION_CREATED));
          self::assertTrue(EventType::isImmutable(EventType::SUBSCRIPTION_CANCELED));
      }

      public function testMutableTypesAreNotImmutable(): void
      {
          self::assertFalse(EventType::isImmutable(EventType::SUBSCRIPTION_UPDATED));
          self::assertFalse(EventType::isImmutable(EventType::SUBSCRIPTION_PAST_DUE));
      }

      public function testKnownVsUnknown(): void
      {
          self::assertTrue(EventType::isKnown('payment.succeeded'));
          self::assertFalse(EventType::isKnown('something.weird'));
          self::assertSame('unknown', EventType::UNKNOWN);
      }
  }
  ```
- [ ] Run (expect FAIL): `vendor/bin/phpunit --filter=EventTypeTest`
- [ ] Create `src/Events/EventType.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Events;

  /**
   * Normalized, gateway-agnostic event-type vocabulary (Workstream 3).
   *
   * Immutable / point-in-time facts key their logical_event_key as "{type}:{entityId}";
   * mutable / repeatable facts add a discriminator ("{type}:{subId}:{discriminator}").
   */
  final class EventType
  {
      public const PAYMENT_SUCCEEDED = 'payment.succeeded';
      public const PAYMENT_FAILED = 'payment.failed';
      public const INVOICE_PAID = 'invoice.paid';
      public const INVOICE_PAYMENT_FAILED = 'invoice.payment_failed';
      public const SUBSCRIPTION_CREATED = 'subscription.created';
      public const SUBSCRIPTION_UPDATED = 'subscription.updated';
      public const SUBSCRIPTION_PAST_DUE = 'subscription.past_due';
      public const SUBSCRIPTION_CANCELED = 'subscription.canceled';
      public const UNKNOWN = 'unknown';

      /** @var list<string> */
      private const IMMUTABLE = [
          self::PAYMENT_SUCCEEDED,
          self::PAYMENT_FAILED,
          self::INVOICE_PAID,
          self::INVOICE_PAYMENT_FAILED,
          self::SUBSCRIPTION_CREATED,
          self::SUBSCRIPTION_CANCELED,
      ];

      /** @var list<string> */
      private const MUTABLE = [
          self::SUBSCRIPTION_UPDATED,
          self::SUBSCRIPTION_PAST_DUE,
      ];

      public static function isImmutable(string $type): bool
      {
          return in_array($type, self::IMMUTABLE, true);
      }

      public static function isKnown(string $type): bool
      {
          return in_array($type, self::IMMUTABLE, true)
              || in_array($type, self::MUTABLE, true);
      }
  }
  ```
- [ ] Run (expect PASS): `vendor/bin/phpunit --filter=EventTypeTest`
- [ ] Commit: `git add src/Events/EventType.php tests/Unit/Events/EventTypeTest.php && git commit -m "feat(events): normalized provider event-type vocabulary"`

### Task 2.2 -- `PaymentProviderEventInterface` contract

**Files:**
- Create: `src/Contracts/PaymentProviderEventInterface.php`

**Steps:**
- [ ] Create `src/Contracts/PaymentProviderEventInterface.php` (exact shape from spec W3):
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Contracts;

  interface PaymentProviderEventInterface
  {
      public function gateway(): string;                 // 'paystack'
      public function type(): string;                    // normalized type (EventType::*)
      public function providerEventId(): ?string;        // native provider event id, or null
      public function deliveryKey(): string;             // provider-delivery idempotency
      public function logicalEventKey(): string;         // business-event dedupe (cross-path safe)
      public function occurredAt(): \DateTimeImmutable;

      /** @return array<string,mixed> Normalized, gateway-agnostic subset (ids, amounts, status, period). */
      public function normalized(): array;

      /** @return array<string,mixed> Untouched provider payload (escape hatch). */
      public function raw(): array;
  }
  ```
- [ ] Commit: `git add src/Contracts/PaymentProviderEventInterface.php && git commit -m "feat(events): PaymentProviderEventInterface contract"`

### Task 2.3 -- `ProviderEvent` VO with logical-key derivation

**Files:**
- Create: `src/Events/ProviderEvent.php`
- Test: `tests/Unit/Events/ProviderEventTest.php`

**Steps:**
- [ ] Write failing test `tests/Unit/Events/ProviderEventTest.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Tests\Unit\Events;

  use Glueful\Extensions\Payvia\Events\EventType;
  use Glueful\Extensions\Payvia\Events\ProviderEvent;
  use PHPUnit\Framework\TestCase;

  final class ProviderEventTest extends TestCase
  {
      public function testImmutableLogicalKeyIsTypeColonEntity(): void
      {
          $e = ProviderEvent::create(
              gateway: 'paystack',
              type: EventType::PAYMENT_SUCCEEDED,
              providerEventId: null,
              deliveryKey: 'hash-of-body',
              entityId: 'REF_123',
              occurredAt: new \DateTimeImmutable('2026-06-09T00:00:00Z'),
              normalized: ['reference' => 'REF_123', 'status' => 'success'],
              raw: ['event' => 'charge.success'],
          );

          self::assertSame('payment.succeeded:REF_123', $e->logicalEventKey());
          self::assertSame('hash-of-body', $e->deliveryKey());
          self::assertNull($e->providerEventId());
      }

      public function testCrossPathImmutableEventsShareLogicalKey(): void
      {
          $fromVerify = ProviderEvent::create('paystack', EventType::PAYMENT_SUCCEEDED, 'txn_1', 'txn_1', 'REF_9', new \DateTimeImmutable(), [], []);
          $fromWebhook = ProviderEvent::create('paystack', EventType::PAYMENT_SUCCEEDED, null, 'evt_body_hash', 'REF_9', new \DateTimeImmutable(), [], []);

          self::assertNotSame($fromVerify->deliveryKey(), $fromWebhook->deliveryKey());
          self::assertSame($fromVerify->logicalEventKey(), $fromWebhook->logicalEventKey());
      }

      public function testMutableLogicalKeyIncludesDiscriminator(): void
      {
          $v1 = ProviderEvent::create('paystack', EventType::SUBSCRIPTION_UPDATED, null, 'd1', 'SUB_1', new \DateTimeImmutable(), ['status' => 'active'], [], discriminator: 'v7');
          $v2 = ProviderEvent::create('paystack', EventType::SUBSCRIPTION_UPDATED, null, 'd2', 'SUB_1', new \DateTimeImmutable(), ['status' => 'past_due'], [], discriminator: 'v8');

          self::assertSame('subscription.updated:SUB_1:v7', $v1->logicalEventKey());
          self::assertNotSame($v1->logicalEventKey(), $v2->logicalEventKey());
      }

      public function testMutableWithoutDiscriminatorHashesNormalizedState(): void
      {
          $a = ProviderEvent::create('paystack', EventType::SUBSCRIPTION_PAST_DUE, null, 'd1', 'SUB_2', new \DateTimeImmutable(), ['status' => 'past_due', 'attempt' => 1], []);
          $aAgain = ProviderEvent::create('paystack', EventType::SUBSCRIPTION_PAST_DUE, null, 'd2', 'SUB_2', new \DateTimeImmutable(), ['status' => 'past_due', 'attempt' => 1], []);
          $b = ProviderEvent::create('paystack', EventType::SUBSCRIPTION_PAST_DUE, null, 'd3', 'SUB_2', new \DateTimeImmutable(), ['status' => 'past_due', 'attempt' => 2], []);

          self::assertSame($a->logicalEventKey(), $aAgain->logicalEventKey()); // same state -> same key
          self::assertNotSame($a->logicalEventKey(), $b->logicalEventKey());   // different state -> different key
      }
  }
  ```
- [ ] Run (expect FAIL): `vendor/bin/phpunit --filter=ProviderEventTest`
- [ ] Create `src/Events/ProviderEvent.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Events;

  use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;

  /**
   * Immutable normalized provider-event value object (Workstream 3).
   *
   * Carries both idempotency keys. deliveryKey() dedupes an exact provider redelivery
   * (native id, else a per-delivery body hash). logicalEventKey() dedupes a business fact
   * across paths/deliveries: coarse "{type}:{entityId}" for immutable facts (so verify +
   * webhook collapse), fine-grained "{type}:{subId}:{discriminator}" for mutable facts
   * (version/sequence/updated_at, or a stable hash of normalized state when absent).
   */
  final class ProviderEvent implements PaymentProviderEventInterface
  {
      /**
       * @param array<string,mixed> $normalized
       * @param array<string,mixed> $raw
       */
      private function __construct(
          private string $gateway,
          private string $type,
          private ?string $providerEventId,
          private string $deliveryKey,
          private string $logicalEventKey,
          private \DateTimeImmutable $occurredAt,
          private array $normalized,
          private array $raw,
      ) {
      }

      /**
       * @param array<string,mixed> $normalized
       * @param array<string,mixed> $raw
       */
      public static function create(
          string $gateway,
          string $type,
          ?string $providerEventId,
          string $deliveryKey,
          string $entityId,
          \DateTimeImmutable $occurredAt,
          array $normalized,
          array $raw,
          ?string $discriminator = null,
      ): self {
          return new self(
              $gateway,
              $type,
              $providerEventId,
              $deliveryKey,
              self::deriveLogicalKey($type, $entityId, $normalized, $discriminator),
              $occurredAt,
              $normalized,
              $raw,
          );
      }

      /**
       * @param array<string,mixed> $normalized
       */
      private static function deriveLogicalKey(
          string $type,
          string $entityId,
          array $normalized,
          ?string $discriminator,
      ): string {
          if (EventType::isImmutable($type)) {
              return $type . ':' . $entityId;
          }

          // Mutable / repeatable: prefer a provider-supplied discriminator; otherwise hash
          // the normalized state so an exact redelivery dedupes but a real change does not.
          if ($discriminator !== null && $discriminator !== '') {
              return $type . ':' . $entityId . ':' . $discriminator;
          }

          $hash = substr(hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR)), 0, 16);
          return $type . ':' . $entityId . ':' . $hash;
      }

      public function gateway(): string
      {
          return $this->gateway;
      }

      public function type(): string
      {
          return $this->type;
      }

      public function providerEventId(): ?string
      {
          return $this->providerEventId;
      }

      public function deliveryKey(): string
      {
          return $this->deliveryKey;
      }

      public function logicalEventKey(): string
      {
          return $this->logicalEventKey;
      }

      public function occurredAt(): \DateTimeImmutable
      {
          return $this->occurredAt;
      }

      public function normalized(): array
      {
          return $this->normalized;
      }

      public function raw(): array
      {
          return $this->raw;
      }
  }
  ```
- [ ] Run (expect PASS): `vendor/bin/phpunit --filter=ProviderEventTest`
- [ ] Commit: `git add src/Events/ProviderEvent.php tests/Unit/Events/ProviderEventTest.php && git commit -m "feat(events): immutable ProviderEvent VO with two-key idempotency derivation"`

### Task 2.4 -- `PaymentProviderEvent` BaseEvent wrapper

**Files:**
- Create: `src/Events/PaymentProviderEvent.php`
- Test: `tests/Unit/Events/ProviderEventTest.php` (add a case)

**Steps:**
- [ ] Add failing test to `ProviderEventTest`:
  ```php
      public function testBaseEventCarriesTypedVo(): void
      {
          $vo = ProviderEvent::create('paystack', EventType::PAYMENT_SUCCEEDED, null, 'd', 'R', new \DateTimeImmutable(), [], []);
          $event = new \Glueful\Extensions\Payvia\Events\PaymentProviderEvent($vo);

          self::assertSame($vo, $event->event);
          self::assertSame('payment.succeeded', $event->event->type());
          self::assertNotSame('', $event->getEventId()); // BaseEvent initialized
      }
  ```
- [ ] Run (expect FAIL): `vendor/bin/phpunit --filter=testBaseEventCarriesTypedVo`
- [ ] Create `src/Events/PaymentProviderEvent.php` (D5 -- single class carrying typed VO):
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Events;

  use Glueful\Events\Contracts\BaseEvent;
  use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;

  /**
   * Single PSR-14 event carrying a normalized provider-event VO (D5).
   * Consumers register one listener and switch on $event->event->type().
   */
  final class PaymentProviderEvent extends BaseEvent
  {
      public function __construct(public readonly PaymentProviderEventInterface $event)
      {
          parent::__construct();
      }
  }
  ```
- [ ] Run (expect PASS): `vendor/bin/phpunit --filter=testBaseEventCarriesTypedVo`
- [ ] Commit: `git add src/Events/PaymentProviderEvent.php tests/Unit/Events/ProviderEventTest.php && git commit -m "feat(events): PaymentProviderEvent BaseEvent wrapper"`

---

## Phase 3 -- provider_events dedupe + webhook ingestion (D3, D4)

### Task 3.1 -- Capability interfaces

**Files:**
- Create: `src/Contracts/WebhookCapableGateway.php`
- Create: `src/Contracts/SubscriptionCapableGateway.php`

**Steps:**
- [ ] Create `src/Contracts/WebhookCapableGateway.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Contracts;

  interface WebhookCapableGateway
  {
      /** @param array<string,mixed> $headers */
      public function verifyWebhookSignature(string $rawBody, array $headers): bool;

      /** @param array<string,mixed> $headers */
      public function parseWebhookEvent(string $rawBody, array $headers): PaymentProviderEventInterface;
  }
  ```
- [ ] Create `src/Contracts/SubscriptionCapableGateway.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Contracts;

  interface SubscriptionCapableGateway
  {
      /** @return array<string,mixed> raw provider object */
      public function fetchSubscription(string $gatewaySubscriptionId): array;

      /** @return array<string,mixed> raw provider object */
      public function cancelSubscription(string $gatewaySubscriptionId, bool $atPeriodEnd = true): array;
  }
  ```
- [ ] Commit: `git add src/Contracts/WebhookCapableGateway.php src/Contracts/SubscriptionCapableGateway.php && git commit -m "feat(gateways): webhook + subscription capability interfaces (ISP, D9)"`

### Task 3.2 -- `GatewayManager` capability resolution

**Files:**
- Modify: `src/GatewayManager.php` (add `supports`, `webhookGateway`, `subscriptionGateway`)
- Create: `tests/Support/FakeWebhookGateway.php`
- Test: `tests/Integration/GatewayManagerCapabilityTest.php`

**Steps:**
- [ ] Create `tests/Support/FakeWebhookGateway.php` (in-suite fake; implements all three contracts):
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Tests\Support;

  use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
  use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
  use Glueful\Extensions\Payvia\Contracts\SubscriptionCapableGateway;
  use Glueful\Extensions\Payvia\Contracts\WebhookCapableGateway;
  use Glueful\Extensions\Payvia\Events\EventType;
  use Glueful\Extensions\Payvia\Events\ProviderEvent;

  final class FakeWebhookGateway implements
      PaymentGatewayInterface,
      WebhookCapableGateway,
      SubscriptionCapableGateway
  {
      public bool $signatureValid = true;
      /** @var array<string,mixed> */
      public array $fetchResult = [];

      public function verify(string $reference, array $options = []): array
      {
          return ['status' => 'success', 'reference' => $reference, 'amount' => 1.0, 'currency' => 'GHS'];
      }

      public function verifyWebhookSignature(string $rawBody, array $headers): bool
      {
          return $this->signatureValid;
      }

      public function parseWebhookEvent(string $rawBody, array $headers): PaymentProviderEventInterface
      {
          /** @var array<string,mixed> $payload */
          $payload = json_decode($rawBody, true) ?: [];
          $type = (string) ($payload['type'] ?? EventType::UNKNOWN);
          $entityId = (string) ($payload['entity_id'] ?? 'X');

          return ProviderEvent::create(
              gateway: 'fake',
              type: $type,
              providerEventId: $payload['provider_event_id'] ?? null,
              deliveryKey: (string) ($payload['delivery_key'] ?? hash('sha256', $rawBody)),
              entityId: $entityId,
              occurredAt: new \DateTimeImmutable(),
              normalized: (array) ($payload['normalized'] ?? []),
              raw: $payload,
              discriminator: $payload['discriminator'] ?? null,
          );
      }

      public function fetchSubscription(string $gatewaySubscriptionId): array
      {
          return $this->fetchResult;
      }

      public function cancelSubscription(string $gatewaySubscriptionId, bool $atPeriodEnd = true): array
      {
          return ['gateway_subscription_id' => $gatewaySubscriptionId, 'status' => 'canceled'];
      }
  }
  ```
- [ ] Write failing test `tests/Integration/GatewayManagerCapabilityTest.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Tests\Integration;

  use Glueful\Extensions\Payvia\GatewayManager;
  use Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway;
  use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

  final class GatewayManagerCapabilityTest extends PayviaTestCase
  {
      private function manager(): GatewayManager
      {
          // Bind a fake driver + config so GatewayManager resolves 'fake'.
          $this->bind(FakeWebhookGateway::class, new FakeWebhookGateway());
          // GatewayManager reads payvia.gateways config via config(); provide it on the context.
          // PayviaTestCase exposes setConfig() below (add it if not present).
          $this->context->getContainer(); // ensure container set
          return new GatewayManager($this->context->getContainer(), $this->context);
      }

      public function testSupportsAndTypedResolvers(): void
      {
          // Requires payvia.gateways.fake config with driver 'fake' mapped to FakeWebhookGateway.
          $mgr = $this->manager();
          $mgr->registerDriver('fake', FakeWebhookGateway::class);

          self::assertTrue($mgr->supports('fake', 'webhook'));
          self::assertTrue($mgr->supports('fake', 'subscription'));
          self::assertInstanceOf(
              \Glueful\Extensions\Payvia\Contracts\WebhookCapableGateway::class,
              $mgr->webhookGateway('fake')
          );
      }

      public function testUnsupportedCapabilityThrows(): void
      {
          $this->expectException(\RuntimeException::class);
          $mgr = $this->manager();
          // 'paystack' supports it, but an unknown gateway must throw on resolve.
          $mgr->webhookGateway('does-not-exist');
      }
  }
  ```
  > NOTE for implementer: `GatewayManager` currently reads `payvia.gateways` from `config()`. For tests, add a small seam: (1) a public `registerDriver(string $name, string $class): void` that adds to `$this->drivers` and treats the gateway as enabled when no config row exists, OR (2) seed config via the harness. The cleaner choice is `registerDriver()` plus making `gateway()` fall back to an enabled, same-name driver when `payvia.gateways.{name}` is absent but the driver was explicitly registered. Implement `registerDriver()` and have `supports()`/resolvers use it.
- [ ] Run (expect FAIL): `vendor/bin/phpunit --filter=GatewayManagerCapabilityTest`
- [ ] Edit `src/GatewayManager.php` -- add registration seam + capability API. Insert after the `gateway()` method:
  ```php
      /**
       * Register (or override) a driver class for a gateway name. Enables capability
       * tests and runtime driver extension without editing the static map.
       */
      public function registerDriver(string $name, string $class): void
      {
          $this->drivers[$name] = $class;
          unset($this->resolved[$name]);
      }

      public function supports(string $gateway, string $capability): bool
      {
          try {
              $driver = $this->gateway($gateway);
          } catch (\Throwable) {
              return false;
          }

          return match ($capability) {
              'webhook' => $driver instanceof WebhookCapableGateway,
              'subscription' => $driver instanceof SubscriptionCapableGateway,
              default => false,
          };
      }

      public function webhookGateway(string $gateway): WebhookCapableGateway
      {
          $driver = $this->gateway($gateway);
          if (!$driver instanceof WebhookCapableGateway) {
              throw new \RuntimeException(
                  "Payvia: gateway '{$gateway}' does not support webhooks."
              );
          }
          return $driver;
      }

      public function subscriptionGateway(string $gateway): SubscriptionCapableGateway
      {
          $driver = $this->gateway($gateway);
          if (!$driver instanceof SubscriptionCapableGateway) {
              throw new \RuntimeException(
                  "Payvia: gateway '{$gateway}' does not support subscriptions."
              );
          }
          return $driver;
      }
  ```
  Add imports at the top of `GatewayManager.php`:
  ```php
  use Glueful\Extensions\Payvia\Contracts\SubscriptionCapableGateway;
  use Glueful\Extensions\Payvia\Contracts\WebhookCapableGateway;
  ```
  Also relax `gateway()` so an explicitly-registered driver with no config row is treated as enabled: after the `$config = (array) config(...)` line, change the guard to:
  ```php
          if (!isset($config[$name]) && !isset($this->drivers[$name])) {
              throw new \RuntimeException("Payvia: gateway '{$name}' is not configured or disabled.");
          }
          if (isset($config[$name]) && (($config[$name]['enabled'] ?? true) === false)) {
              throw new \RuntimeException("Payvia: gateway '{$name}' is disabled.");
          }
          $gatewayConfig = (array) ($config[$name] ?? []);
          $driver = (string) ($gatewayConfig['driver'] ?? $name);
  ```
- [ ] Run (expect PASS): `vendor/bin/phpunit --filter=GatewayManagerCapabilityTest`
- [ ] Commit: `git add src/GatewayManager.php tests/Support/FakeWebhookGateway.php tests/Integration/GatewayManagerCapabilityTest.php && git commit -m "feat(gateways): GatewayManager capability resolution (supports/webhookGateway/subscriptionGateway)"`

### Task 3.3 -- Migration: `provider_events` table

**Files:**
- Create: `migrations/005_CreateProviderEventsTable.php`
- Test: `tests/Integration/MigrationsTest.php` (add a case)

**Steps:**
- [ ] Add failing test to `MigrationsTest`:
  ```php
      public function testProviderEventsTableHasTwoKeysAndOutboxColumns(): void
      {
          $schema = $this->connection->getSchemaBuilder();
          (new \Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable())->up($schema);

          self::assertTrue($schema->hasTable('provider_events'));
          foreach (['delivery_key', 'logical_event_key', 'status', 'dispatch_status', 'dispatched_at', 'dispatch_claimed_at', 'attempts', 'signature_valid', 'normalized_payload'] as $col) {
              self::assertTrue($schema->hasColumn('provider_events', $col), "missing {$col}");
          }
      }
  ```
- [ ] Run (expect FAIL): `vendor/bin/phpunit --filter=testProviderEventsTableHasTwoKeysAndOutboxColumns`
- [ ] Create `migrations/005_CreateProviderEventsTable.php`:
  ```php
  <?php

  namespace Glueful\Extensions\Payvia\Database\Migrations;

  use Glueful\Database\Migrations\MigrationInterface;
  use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

  /**
   * Cross-path (webhook + verify) idempotency + audit (Workstream 2).
   *
   * Two keys: unique (gateway, delivery_key) is the DB-enforced ingest boundary; indexed
   * (gateway, logical_event_key) gates outbox dispatch. Status-aware lifecycle
   * (received -> processing -> processed/failed) is separate from the outbox
   * (dispatch_status pending -> dispatching -> dispatched) so a post-processing crash is
   * replayable. normalized_payload stores Payvia's normalized event shape durably so
   * queued/relay replay reconstructs the event without depending on the provider raw body.
   */
  class CreateProviderEventsTable implements MigrationInterface
  {
      public function up(SchemaBuilderInterface $schema): void
      {
          if ($schema->hasTable('provider_events')) {
              return;
          }

          $schema->createTable('provider_events', function ($table) {
              $table->bigInteger('id')->primary()->autoIncrement();
              $table->string('uuid', 12);

              $table->string('gateway', 50);
              $table->string('source', 20);                 // 'webhook' | 'verify'
              $table->string('provider_event_id', 191)->nullable();
              $table->string('delivery_key', 191);
              $table->string('logical_event_key', 191);
              $table->string('type', 100);

              $table->string('status', 20)->default('received');
              $table->string('dispatch_status', 20)->default('pending');
              $table->timestamp('dispatched_at')->nullable();
              // Advanced on EVERY claim (fresh + stale-reclaim). The changing column that makes
              // the claim UPDATE's affected-row count a reliable ownership signal even on drivers
              // reporting CHANGED (not MATCHED) rows; stale-reclaim filters on dispatch_claimed_at < cutoff.
              $table->timestamp('dispatch_claimed_at')->nullable();
              $table->integer('attempts')->default(0);

              $table->boolean('signature_valid')->default(false);
              // Durable normalized snapshot -- REQUIRED for queued/relay replay (a provider
              // raw payload does not contain Payvia's normalized shape). NOT gated by
              // store_raw_payload; raw_payload (the provider escape hatch) stays gated.
              $table->json('normalized_payload')->nullable();
              $table->json('raw_payload')->nullable();
              $table->string('error', 255)->nullable();

              $table->timestamp('received_at')->default('CURRENT_TIMESTAMP');
              $table->timestamp('processed_at')->nullable();

              $table->unique('uuid');
              // Ingest boundary: one row per exact provider delivery, per gateway.
              $table->unique(['gateway', 'delivery_key']);
              // Dispatch dedupe: many deliveries can map to one logical fact.
              $table->index(['gateway', 'logical_event_key']);
              $table->index('status');
              $table->index('dispatch_status');
          });
      }

      public function down(SchemaBuilderInterface $schema): void
      {
          $schema->dropTableIfExists('provider_events');
      }

      public function getDescription(): string
      {
          return 'Creates provider_events (two-key idempotency + status-aware outbox) for webhook/verify ingestion.';
      }
  }
  ```
- [ ] Run (expect PASS): `vendor/bin/phpunit --filter=testProviderEventsTableHasTwoKeysAndOutboxColumns`
- [ ] Commit: `git add migrations/005_CreateProviderEventsTable.php tests/Integration/MigrationsTest.php && git commit -m "feat(events): provider_events table (two-key idempotency + outbox)"`

### Task 3.4 -- `ProviderEventRepository` + contract (status + outbox transitions)

**Files:**
- Create: `src/Contracts/ProviderEventRepositoryInterface.php`
- Create: `src/Repositories/ProviderEventRepository.php`
- Test: `tests/Integration/ProviderEventRepositoryTest.php`

**Steps:**
- [ ] Create `src/Contracts/ProviderEventRepositoryInterface.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Contracts;

  interface ProviderEventRepositoryInterface
  {
      /** @return array<string,mixed>|null */
      public function findByDeliveryKey(string $gateway, string $deliveryKey): ?array;

      /**
       * Insert a new `received` row. Returns the row uuid, or null if a concurrent insert
       * won the unique (gateway, delivery_key) race (caller should re-read and branch).
       *
       * @param array<string,mixed> $data
       */
      public function insertReceived(array $data): ?string;

      public function markProcessing(string $uuid): void;

      public function markProcessed(string $uuid): void;

      public function markFailed(string $uuid, string $error): void;

      public function incrementAttempts(string $uuid): void;

      /** Has any row for this logical fact already reached the terminal `dispatched` state? */
      public function isLogicalDispatched(string $gateway, string $logicalEventKey): bool;

      /**
       * Atomically claim the logical group for dispatch. Conditional UPDATE flips every
       * `pending` row for (gateway, logical_event_key) to `dispatching`; returns the number
       * of rows affected. affectedRows >= 1 means THIS caller won the claim (it must emit
       * then markLogicalDispatched); affectedRows == 0 means another worker already
       * claimed/dispatched it (caller must NOT emit). Concurrent claims serialize on row
       * locks, so exactly one caller sees affectedRows >= 1.
       */
      public function claimLogicalForDispatch(string $gateway, string $logicalEventKey): int;

      /**
       * Re-claim rows stuck in `dispatching` past $staleSeconds (claimed but never emitted,
       * e.g. a crash between claim and emit) by flipping them back to `dispatching` via the
       * SAME atomic conditional UPDATE. Returns affected rows (>= 1 means this caller may emit).
       */
      public function reclaimStaleDispatching(string $gateway, string $logicalEventKey, int $staleSeconds): int;

      /** Terminal: mark every row for this logical fact `dispatched` after a successful emit. */
      public function markLogicalDispatched(string $gateway, string $logicalEventKey): void;

      /** Mark a single row `dispatched` without emitting (already-delivered duplicate / no listener target). */
      public function markDispatched(string $uuid): void;

      /**
       * Outbox relay candidates: rows that still need dispatching -- `processed`+`pending`,
       * plus rows stuck in `dispatching` past $staleSeconds (crash recovery).
       *
       * @return array<int,array<string,mixed>>
       */
      public function findDispatchable(int $limit = 100, int $staleSeconds = 300): array;

      /** @return array<string,mixed>|null */
      public function findByUuid(string $uuid): ?array;
  }
  ```
- [ ] Write failing test `tests/Integration/ProviderEventRepositoryTest.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Tests\Integration;

  use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
  use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
  use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

  final class ProviderEventRepositoryTest extends PayviaTestCase
  {
      private ProviderEventRepository $repo;

      protected function setUp(): void
      {
          parent::setUp();
          $this->runMigration(new CreateProviderEventsTable());
          $this->repo = new ProviderEventRepository($this->connection);
      }

      /** @return array<string,mixed> */
      private function row(string $deliveryKey, string $logicalKey, string $type = 'payment.succeeded'): array
      {
          return [
              'gateway' => 'paystack',
              'source' => 'webhook',
              'provider_event_id' => null,
              'delivery_key' => $deliveryKey,
              'logical_event_key' => $logicalKey,
              'type' => $type,
              'signature_valid' => true,
              'raw_payload' => null,
          ];
      }

      public function testInsertAndStatusTransitions(): void
      {
          $uuid = $this->repo->insertReceived($this->row('d1', 'payment.succeeded:R1'));
          self::assertNotNull($uuid);

          $this->repo->markProcessing($uuid);
          $this->repo->markProcessed($uuid);
          $stored = $this->repo->findByUuid($uuid);
          self::assertSame('processed', $stored['status']);
          self::assertSame('pending', $stored['dispatch_status']);
      }

      public function testDuplicateDeliveryKeyInsertReturnsNull(): void
      {
          self::assertNotNull($this->repo->insertReceived($this->row('dup', 'payment.succeeded:R2')));
          self::assertNull($this->repo->insertReceived($this->row('dup', 'payment.succeeded:R2')));
      }

      public function testAtomicClaimWinsOnceThenZero(): void
      {
          $u1 = $this->repo->insertReceived($this->row('d-verify', 'payment.succeeded:R3'));
          $u2 = $this->repo->insertReceived($this->row('d-webhook', 'payment.succeeded:R3'));
          self::assertFalse($this->repo->isLogicalDispatched('paystack', 'payment.succeeded:R3'));

          // First claim flips both pending rows for the logical key -> wins.
          self::assertGreaterThanOrEqual(1, $this->repo->claimLogicalForDispatch('paystack', 'payment.succeeded:R3'));
          // Second claim sees no `pending` rows -> loses (no double dispatch).
          self::assertSame(0, $this->repo->claimLogicalForDispatch('paystack', 'payment.succeeded:R3'));

          // Winner emits then marks terminal.
          $this->repo->markLogicalDispatched('paystack', 'payment.succeeded:R3');
          self::assertTrue($this->repo->isLogicalDispatched('paystack', 'payment.succeeded:R3'));
          self::assertNotNull($u1);
          self::assertNotNull($u2);
      }

      public function testFindDispatchableReturnsProcessedPendingRows(): void
      {
          $u = $this->repo->insertReceived($this->row('d-relay', 'invoice.paid:INV1', 'invoice.paid'));
          $this->repo->markProcessing($u);
          $this->repo->markProcessed($u);

          $rows = $this->repo->findDispatchable();
          self::assertCount(1, $rows);
          self::assertSame('d-relay', $rows[0]['delivery_key']);
      }

      public function testFindDispatchableIncludesStaleDispatchingRows(): void
      {
          // Row claimed (dispatching) but never emitted, with an old dispatch_claimed_at -> crash.
          $u = $this->repo->insertReceived($this->row('d-stale', 'invoice.paid:INV2', 'invoice.paid'));
          $this->repo->markProcessing($u);
          $this->repo->markProcessed($u);
          $this->repo->claimLogicalForDispatch('paystack', 'invoice.paid:INV2');
          // Backdate the claim so the row is past the stale window (staleness keys on dispatch_claimed_at).
          $this->connection->table('provider_events')
              ->where(['uuid' => $u])
              ->update(['dispatch_claimed_at' => $this->connection->getDriver()->formatDateTime(new \DateTimeImmutable('-1 hour'))]);

          // Default stale window (300s) -> the dispatching row is recovered.
          $rows = $this->repo->findDispatchable();
          self::assertSame(['d-stale'], array_map(static fn ($r) => $r['delivery_key'], $rows));
      }
  }
  ```
- [ ] Run (expect FAIL): `vendor/bin/phpunit --filter=ProviderEventRepositoryTest`
- [ ] Create `src/Repositories/ProviderEventRepository.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Repositories;

  use Glueful\Extensions\Payvia\Contracts\ProviderEventRepositoryInterface;
  use Glueful\Helpers\Utils;
  use Glueful\Repository\BaseRepository;

  final class ProviderEventRepository extends BaseRepository implements ProviderEventRepositoryInterface
  {
      public function getTableName(): string
      {
          return 'provider_events';
      }

      public function findByDeliveryKey(string $gateway, string $deliveryKey): ?array
      {
          $rows = $this->db->table($this->getTableName())
              ->select(['*'])
              ->where(['gateway' => $gateway, 'delivery_key' => $deliveryKey])
              ->limit(1)
              ->get();

          return $rows[0] ?? null;
      }

      public function insertReceived(array $data): ?string
      {
          $uuid = Utils::generateNanoID(12);
          $payload = array_merge($data, [
              'uuid' => $uuid,
              'status' => 'received',
              'dispatch_status' => 'pending',
              'attempts' => 0,
              'received_at' => $this->db->getDriver()->formatDateTime(),
          ]);

          try {
              $this->db->table($this->getTableName())->insert($payload);
          } catch (\Throwable) {
              // Unique (gateway, delivery_key) race lost to a concurrent insert.
              return null;
          }

          return $uuid;
      }

      public function markProcessing(string $uuid): void
      {
          $this->db->table($this->getTableName())
              ->where(['uuid' => $uuid])
              ->update(['status' => 'processing']);
      }

      public function markProcessed(string $uuid): void
      {
          $this->db->table($this->getTableName())
              ->where(['uuid' => $uuid])
              ->update([
                  'status' => 'processed',
                  'processed_at' => $this->db->getDriver()->formatDateTime(),
              ]);
      }

      public function markFailed(string $uuid, string $error): void
      {
          $this->db->table($this->getTableName())
              ->where(['uuid' => $uuid])
              ->update([
                  'status' => 'failed',
                  'error' => substr($error, 0, 255),
              ]);
          $this->incrementAttempts($uuid);
      }

      public function incrementAttempts(string $uuid): void
      {
          $row = $this->findByUuid($uuid);
          $attempts = (int) ($row['attempts'] ?? 0) + 1;
          $this->db->table($this->getTableName())
              ->where(['uuid' => $uuid])
              ->update(['attempts' => $attempts]);
      }

      public function isLogicalDispatched(string $gateway, string $logicalEventKey): bool
      {
          $rows = $this->db->table($this->getTableName())
              ->select(['uuid'])
              ->where(['gateway' => $gateway, 'logical_event_key' => $logicalEventKey, 'dispatch_status' => 'dispatched'])
              ->limit(1)
              ->get();

          return $rows !== [];
      }

      public function claimLogicalForDispatch(string $gateway, string $logicalEventKey): int
      {
          // Atomic conditional UPDATE: only `pending` rows flip to `dispatching`. The DB
          // serializes concurrent writers on the matched rows, so exactly one caller sees
          // a non-zero affected count for a given pending group. Portable across
          // MySQL/Postgres/SQLite (plain UPDATE ... WHERE on indexed columns).
          return $this->db->table($this->getTableName())
              ->where([
                  'gateway' => $gateway,
                  'logical_event_key' => $logicalEventKey,
                  'dispatch_status' => 'pending',
              ])
              ->update([
                  'dispatch_status' => 'dispatching',
                  'dispatch_claimed_at' => $this->db->getDriver()->formatDateTime(),
              ]);
      }

      public function reclaimStaleDispatching(string $gateway, string $logicalEventKey, int $staleSeconds): int
      {
          // Crash recovery: re-claim rows left in `dispatching` past the timeout (claimed but never
          // emitted). The row STAYS `dispatching`, so the claim must change a DIFFERENT column for
          // the affected-row count to be a reliable ownership signal: on drivers that report CHANGED
          // (not MATCHED) rows (e.g. MySQL default), `SET dispatch_status='dispatching'` on a row
          // already `dispatching` reports 0 and the reclaim would NEVER win. Advancing
          // dispatch_claimed_at is a real change AND moves the row out of the `< cutoff` predicate,
          // so exactly one relay worker wins. Stale = dispatch_claimed_at < cutoff.
          $cutoff = $this->db->getDriver()->formatDateTime(new \DateTimeImmutable("-{$staleSeconds} seconds"));

          return $this->db->table($this->getTableName())
              ->where(['gateway' => $gateway, 'logical_event_key' => $logicalEventKey, 'dispatch_status' => 'dispatching'])
              ->where('dispatch_claimed_at', '<', $cutoff)
              ->update(['dispatch_claimed_at' => $this->db->getDriver()->formatDateTime()]);
      }

      public function markLogicalDispatched(string $gateway, string $logicalEventKey): void
      {
          $this->db->table($this->getTableName())
              ->where(['gateway' => $gateway, 'logical_event_key' => $logicalEventKey])
              ->whereIn('dispatch_status', ['pending', 'dispatching'])
              ->update([
                  'dispatch_status' => 'dispatched',
                  'dispatched_at' => $this->db->getDriver()->formatDateTime(),
              ]);
      }

      public function markDispatched(string $uuid): void
      {
          $this->db->table($this->getTableName())
              ->where(['uuid' => $uuid])
              ->update([
                  'dispatch_status' => 'dispatched',
                  'dispatched_at' => $this->db->getDriver()->formatDateTime(),
              ]);
      }

      public function findDispatchable(int $limit = 100, int $staleSeconds = 300): array
      {
          // processed+pending (normal) UNION dispatching-but-stale (crash recovery).
          $cutoff = $this->db->getDriver()->formatDateTime(new \DateTimeImmutable("-{$staleSeconds} seconds"));

          $pending = $this->db->table($this->getTableName())
              ->select(['*'])
              ->where(['status' => 'processed', 'dispatch_status' => 'pending'])
              ->limit($limit)
              ->get();

          $stale = $this->db->table($this->getTableName())
              ->select(['*'])
              ->where(['dispatch_status' => 'dispatching'])
              ->where('dispatch_claimed_at', '<', $cutoff)
              ->limit($limit)
              ->get();

          // De-dupe by uuid (a row can only appear in one branch, but guard anyway).
          $byUuid = [];
          foreach ([...$pending, ...$stale] as $row) {
              $byUuid[(string) $row['uuid']] = $row;
          }

          return array_values($byUuid);
      }

      public function findByUuid(string $uuid): ?array
      {
          $rows = $this->db->table($this->getTableName())
              ->select(['*'])
              ->where(['uuid' => $uuid])
              ->limit(1)
              ->get();

          return $rows[0] ?? null;
      }
  }
  ```
  > NOTE for implementer: confirm `BaseRepository` exposes `$this->db` (the existing `PaymentRepository` uses `$this->db->table(...)` and `$this->db->getDriver()->formatDateTime()`), and that `->limit(1)` exists on `QueryBuilder` (used by `PaymentRepository::findByReference`). Both are established payvia patterns. `update()` MUST return the affected-row count (verified: `update(array): int`) -- `claimLogicalForDispatch()`/`reclaimStaleDispatching()` depend on it as the atomic win signal. Confirm `formatDateTime()` accepts a `\DateTimeImmutable` argument (used by the stale-cutoff calls); if the existing helper is no-arg, format the cutoff with `(new \DateTimeImmutable("-{$staleSeconds} seconds"))->format('Y-m-d H:i:s')` to match the driver's stored timestamp format.
- [ ] Run (expect PASS): `vendor/bin/phpunit --filter=ProviderEventRepositoryTest`
- [ ] Commit: `git add src/Contracts/ProviderEventRepositoryInterface.php src/Repositories/ProviderEventRepository.php tests/Integration/ProviderEventRepositoryTest.php && git commit -m "feat(events): ProviderEventRepository with status + outbox transitions"`

### Task 3.5 -- Paystack webhook signature + event parsing

**Files:**
- Modify: `src/Gateways/PaystackGateway.php` (add `implements WebhookCapableGateway`, two methods + a mapper)
- Test: `tests/Unit/PaystackWebhookSignatureTest.php`

**Steps:**
- [ ] Write failing test `tests/Unit/PaystackWebhookSignatureTest.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Tests\Unit;

  use Glueful\Bootstrap\ApplicationContext;
  use Glueful\Extensions\Payvia\Events\EventType;
  use Glueful\Extensions\Payvia\Gateways\PaystackGateway;
  use Glueful\Http\Client;
  use PHPUnit\Framework\TestCase;
  use Psr\Container\ContainerInterface;

  final class PaystackWebhookSignatureTest extends TestCase
  {
      private function gateway(string $secret): PaystackGateway
      {
          // Config is read via config($context, 'payvia.gateways.paystack', []).
          // Build a context whose container resolves config; simplest is to set the
          // PAYVIA_PAYSTACK_WEBHOOK_SECRET env + a minimal config array.
          $container = new class implements ContainerInterface {
              public function get(string $id): mixed { throw new \RuntimeException($id); }
              public function has(string $id): bool { return false; }
          };
          $ctx = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
          $ctx->setContainer($container);
          // Inject config directly through the app config store the helper reads.
          // If config() reads from a container 'config' service, register it there instead.
          \putenv('PAYVIA_PAYSTACK_WEBHOOK_SECRET=' . $secret);

          $http = $this->createMock(Client::class);
          return new PaystackGateway($http, $ctx);
      }

      public function testValidSignaturePasses(): void
      {
          $secret = 'sk_test_secret';
          $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'R1', 'status' => 'success', 'amount' => 5000, 'currency' => 'GHS', 'id' => 99]]);
          $sig = hash_hmac('sha512', $body, $secret);

          $gw = $this->gateway($secret);
          self::assertTrue($gw->verifyWebhookSignature($body, ['x-paystack-signature' => $sig]));
          self::assertFalse($gw->verifyWebhookSignature($body, ['x-paystack-signature' => 'deadbeef']));
      }

      public function testParseMapsChargeSuccessToPaymentSucceeded(): void
      {
          $secret = 'sk_test_secret';
          $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'R7', 'status' => 'success', 'amount' => 5000, 'currency' => 'GHS', 'id' => 99]]);

          $event = $this->gateway($secret)->parseWebhookEvent($body, []);
          self::assertSame(EventType::PAYMENT_SUCCEEDED, $event->type());
          self::assertSame('paystack', $event->gateway());
          self::assertSame('payment.succeeded:R7', $event->logicalEventKey());
          // No native event id -> delivery key is the body hash.
          self::assertNull($event->providerEventId());
          self::assertSame(hash('sha256', $body), $event->deliveryKey());
      }
  }
  ```
  > NOTE for implementer: the `config()` helper resolves through the container/config store. If the harness `config()` does not pick up the env directly, register a `'config'` array binding (or use the framework's config loader) so `payvia.gateways.paystack.webhook_secret` resolves to the secret. The contract under test is: `verifyWebhookSignature` does `hash_equals(hash_hmac('sha512', $rawBody, $secret), $headerSig)`. Adjust only the config-plumbing lines, not the assertions.
- [ ] Run (expect FAIL): `vendor/bin/phpunit --filter=PaystackWebhookSignatureTest`
- [ ] Edit `src/Gateways/PaystackGateway.php`: change the class declaration and add imports + methods. In this task `PaystackGateway` gains ONLY `WebhookCapableGateway` (its `verifyWebhookSignature`/`parseWebhookEvent` are implemented below). `SubscriptionCapableGateway` is deferred to Task 4.4, which adds `implements SubscriptionCapableGateway` together with `fetchSubscription()`/`cancelSubscription()` in the same step -- so no intermediate commit ever declares an interface whose methods are unimplemented (which would fatal). Add the imports it needs now (the subscription-interface import is added in 4.4):
  ```php
  use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
  use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
  use Glueful\Extensions\Payvia\Contracts\WebhookCapableGateway;
  use Glueful\Extensions\Payvia\Events\EventType;
  use Glueful\Extensions\Payvia\Events\ProviderEvent;
  ```
  ```php
  final class PaystackGateway implements
      PaymentGatewayInterface,
      WebhookCapableGateway
  ```
  Append the webhook methods now (the `SubscriptionCapableGateway` methods land in Task 4.4):
  ```php
      private function webhookSecret(): string
      {
          $config = (array) config($this->context, 'payvia.gateways.paystack', []);
          $secret = (string) ($config['webhook_secret'] ?? ($config['secret_key'] ?? ''));
          if ($secret === '') {
              // Paystack signs webhooks with the API secret key.
              $secret = (string) ($config['secret_key'] ?? (getenv('PAYVIA_PAYSTACK_WEBHOOK_SECRET') ?: ''));
          }
          return $secret;
      }

      public function verifyWebhookSignature(string $rawBody, array $headers): bool
      {
          $secret = $this->webhookSecret();
          if ($secret === '') {
              return false;
          }

          // Header lookup is case-insensitive (providers vary).
          $provided = '';
          foreach ($headers as $name => $value) {
              if (strtolower((string) $name) === 'x-paystack-signature') {
                  $provided = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
                  break;
              }
          }
          if ($provided === '') {
              return false;
          }

          $expected = hash_hmac('sha512', $rawBody, $secret);
          return hash_equals($expected, $provided);
      }

      public function parseWebhookEvent(string $rawBody, array $headers): PaymentProviderEventInterface
      {
          /** @var array<string,mixed> $payload */
          $payload = json_decode($rawBody, true) ?: [];
          $providerEvent = (string) ($payload['event'] ?? '');
          $data = (array) ($payload['data'] ?? []);

          [$type, $entityId, $normalized, $discriminator] = $this->mapPaystackEvent($providerEvent, $data);

          // Paystack documents no stable event id -> provider_event_id is null and the
          // delivery key is a per-delivery hash of the raw signed body.
          return ProviderEvent::create(
              gateway: 'paystack',
              type: $type,
              providerEventId: null,
              deliveryKey: hash('sha256', $rawBody),
              entityId: $entityId,
              occurredAt: new \DateTimeImmutable(),
              normalized: $normalized,
              raw: $payload,
              discriminator: $discriminator,
          );
      }

      /**
       * @param array<string,mixed> $data
       * @return array{0:string,1:string,2:array<string,mixed>,3:?string}
       */
      private function mapPaystackEvent(string $providerEvent, array $data): array
      {
          return match ($providerEvent) {
              'charge.success' => [
                  EventType::PAYMENT_SUCCEEDED,
                  (string) ($data['reference'] ?? ($data['id'] ?? 'unknown')),
                  $this->normalizeCharge($data),
                  null,
              ],
              'charge.failed' => [
                  EventType::PAYMENT_FAILED,
                  (string) ($data['reference'] ?? ($data['id'] ?? 'unknown')),
                  $this->normalizeCharge($data),
                  null,
              ],
              'invoice.payment_failed' => [
                  EventType::INVOICE_PAYMENT_FAILED,
                  (string) ($data['id'] ?? 'unknown'),
                  ['invoice_id' => (string) ($data['id'] ?? ''), 'status' => 'failed'],
                  null,
              ],
              'invoice.update', 'invoice.create' => [
                  isset($data['paid']) && (bool) $data['paid'] ? EventType::INVOICE_PAID : EventType::UNKNOWN,
                  (string) ($data['id'] ?? 'unknown'),
                  ['invoice_id' => (string) ($data['id'] ?? ''), 'status' => (isset($data['paid']) && $data['paid']) ? 'paid' : 'pending'],
                  null,
              ],
              'subscription.create' => [
                  EventType::SUBSCRIPTION_CREATED,
                  (string) ($data['subscription_code'] ?? ($data['id'] ?? 'unknown')),
                  $this->normalizeSubscription($data),
                  null,
              ],
              'subscription.disable', 'subscription.not_renew' => [
                  EventType::SUBSCRIPTION_CANCELED,
                  (string) ($data['subscription_code'] ?? ($data['id'] ?? 'unknown')),
                  $this->normalizeSubscription($data),
                  null,
              ],
              default => [
                  EventType::UNKNOWN,
                  (string) ($data['id'] ?? ($data['reference'] ?? 'unknown')),
                  [],
                  null,
              ],
          };
      }

      /**
       * @param array<string,mixed> $data
       * @return array<string,mixed>
       */
      private function normalizeCharge(array $data): array
      {
          return [
              'reference' => (string) ($data['reference'] ?? ''),
              'gateway_transaction_id' => isset($data['id']) ? (string) $data['id'] : null,
              'status' => (string) ($data['status'] ?? ''),
              'amount' => isset($data['amount']) ? ((float) $data['amount'] / 100.0) : 0.0,
              'currency' => (string) ($data['currency'] ?? 'GHS'),
          ];
      }

      /**
       * @param array<string,mixed> $data
       * @return array<string,mixed>
       */
      private function normalizeSubscription(array $data): array
      {
          return [
              'gateway_subscription_id' => (string) ($data['subscription_code'] ?? ($data['id'] ?? '')),
              'gateway_customer_id' => isset($data['customer']['customer_code'])
                  ? (string) $data['customer']['customer_code'] : null,
              'gateway_price_id' => isset($data['plan']['plan_code']) ? (string) $data['plan']['plan_code'] : null,
              'status' => $this->mapSubscriptionStatus((string) ($data['status'] ?? '')),
              'current_period_start' => $data['createdAt'] ?? null,
              'current_period_end' => $data['next_payment_date'] ?? null,
              'cancel_at_period_end' => false,
          ];
      }

      private function mapSubscriptionStatus(string $paystackStatus): string
      {
          return match ($paystackStatus) {
              'active' => 'active',
              'attention' => 'past_due',
              'non-renewing' => 'active',
              'cancelled', 'complete' => 'canceled',
              default => 'incomplete',
          };
      }
  ```
- [ ] Run (expect PASS): `vendor/bin/phpunit --filter=PaystackWebhookSignatureTest`
- [ ] Commit: `git add src/Gateways/PaystackGateway.php tests/Unit/PaystackWebhookSignatureTest.php && git commit -m "feat(paystack): HMAC SHA512 webhook verify + normalized event mapping"`

### Task 3.6 -- `WebhookService` ingest -> apply -> dispatch pipeline (sync path)

**Files:**
- Create: `src/Services/WebhookService.php`
- Test: `tests/Integration/WebhookIngestionTest.php`

**Steps:**
- [ ] Write failing test `tests/Integration/WebhookIngestionTest.php` covering new/duplicate/cross-path/crash-replay/race:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Tests\Integration;

  use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
  use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
  use Glueful\Extensions\Payvia\GatewayManager;
  use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
  use Glueful\Extensions\Payvia\Services\WebhookService;
  use Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway;
  use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

  final class WebhookIngestionTest extends PayviaTestCase
  {
      /** @var list<PaymentProviderEvent> */
      private array $dispatched = [];
      private FakeWebhookGateway $fake;
      private WebhookService $service;
      private ProviderEventRepository $events;

      protected function setUp(): void
      {
          parent::setUp();
          $this->runMigration(new CreateProviderEventsTable());

          $this->fake = new FakeWebhookGateway();
          $this->bind(FakeWebhookGateway::class, $this->fake);
          $manager = new GatewayManager($this->context->getContainer(), $this->context);
          $manager->registerDriver('fake', FakeWebhookGateway::class);

          $this->events = new ProviderEventRepository($this->connection);

          // Dispatcher captures emitted events; no real side-effect appliers needed for
          // dispatch-dedupe assertions (appliers are exercised in their own tests).
          $dispatcher = function (object $event): void {
              if ($event instanceof PaymentProviderEvent) {
                  $this->dispatched[] = $event;
              }
          };

          $this->service = new WebhookService(
              $this->context,
              $manager,
              $this->events,
              $dispatcher,            // closure seam; prod uses EventService::dispatch
              applier: null,          // null applier = no-op side effects for these tests
              queue: false,
          );
      }

      private function body(string $deliveryKey, string $entityId, string $type = 'payment.succeeded'): string
      {
          return json_encode([
              'type' => $type,
              'entity_id' => $entityId,
              'delivery_key' => $deliveryKey,
              'normalized' => ['reference' => $entityId, 'status' => 'success'],
          ]);
      }

      public function testNewEventIsProcessedAndDispatchedOnce(): void
      {
          $this->service->ingest('fake', $this->body('d1', 'R1'), []);
          self::assertCount(1, $this->dispatched);

          $row = $this->events->findByDeliveryKey('fake', 'd1');
          self::assertSame('processed', $row['status']);
          self::assertSame('dispatched', $row['dispatch_status']);
      }

      public function testExactRedeliveryDoesNotDoubleDispatch(): void
      {
          $this->service->ingest('fake', $this->body('d2', 'R2'), []);
          $this->service->ingest('fake', $this->body('d2', 'R2'), []); // same delivery key
          self::assertCount(1, $this->dispatched);
      }

      public function testCrossPathLogicalDuplicateDispatchesOnce(): void
      {
          // Two distinct deliveries (different delivery_key) for the same logical fact.
          $this->service->ingest('fake', $this->body('d-a', 'R3'), []);
          $this->service->ingest('fake', $this->body('d-b', 'R3'), []);
          self::assertCount(1, $this->dispatched);

          // Both deliveries are audited.
          self::assertNotNull($this->events->findByDeliveryKey('fake', 'd-a'));
          self::assertNotNull($this->events->findByDeliveryKey('fake', 'd-b'));
      }

      public function testInvalidSignatureRejectedAndNotPersisted(): void
      {
          $this->fake->signatureValid = false;
          $result = $this->service->ingest('fake', $this->body('d-bad', 'R4'), []);
          self::assertFalse($result->accepted);
          self::assertSame(401, $result->httpStatus);
          self::assertNull($this->events->findByDeliveryKey('fake', 'd-bad'));
          self::assertCount(0, $this->dispatched);
      }

      public function testReplayOfProcessedButUndispatchedRowDispatches(): void
      {
          // Simulate crash-after-processed: insert a processed+pending row directly.
          $uuid = $this->events->insertReceived([
              'gateway' => 'fake', 'source' => 'webhook', 'provider_event_id' => null,
              'delivery_key' => 'd-crash', 'logical_event_key' => 'payment.succeeded:R5',
              'type' => 'payment.succeeded', 'signature_valid' => true,
              'normalized_payload' => json_encode(['reference' => 'R5', 'status' => 'success']),
              'raw_payload' => null,
          ]);
          $this->events->markProcessing($uuid);
          $this->events->markProcessed($uuid);

          // Redelivery of the same delivery_key must re-run DISPATCH (outbox), not re-apply.
          $this->service->ingest('fake', $this->body('d-crash', 'R5'), []);
          self::assertCount(1, $this->dispatched);
          self::assertSame('dispatched', $this->events->findByUuid($uuid)['dispatch_status']);
      }
  }
  ```
  > NOTE: `ingest()` returns a small result object (`WebhookIngestResult`) with `bool $accepted`, `int $httpStatus`. Define it as a nested/readonly class inside `WebhookService.php`.
- [ ] Run (expect FAIL): `vendor/bin/phpunit --filter=WebhookIngestionTest`
- [ ] Create `src/Services/WebhookService.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Services;

  use Glueful\Bootstrap\ApplicationContext;
  use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
  use Glueful\Extensions\Payvia\Contracts\ProviderEventRepositoryInterface;
  use Glueful\Extensions\Payvia\Events\EventType;
  use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
  use Glueful\Extensions\Payvia\GatewayManager;

  final class WebhookIngestResult
  {
      public function __construct(
          public readonly bool $accepted,
          public readonly int $httpStatus,
          public readonly string $message = 'ok',
      ) {
      }
  }

  /**
   * Webhook ingestion pipeline (Workstream 2 + D3/D6).
   *
   * ingest(): verify signature -> parse -> persist on unique (gateway, delivery_key) ->
   * APPLY side effects (idempotent) -> mark processed -> DISPATCH via outbox gated on
   * logical_event_key. Persist-then-process so a crash never loses a verified event; the
   * outbox makes dispatch replayable.
   *
   * The $dispatcher and $applier are injected callables so the service stays testable and
   * does not hard-couple to EventService / payment+subscription services.
   */
  final class WebhookService
  {
      /** @var callable(object):void */
      private $dispatcher;
      /** @var null|callable(PaymentProviderEventInterface):void */
      private $applier;

      /**
       * @param callable(object):void $dispatcher
       * @param null|callable(PaymentProviderEventInterface):void $applier
       */
      public function __construct(
          private ApplicationContext $context,
          private GatewayManager $gateways,
          private ProviderEventRepositoryInterface $events,
          callable $dispatcher,
          ?callable $applier = null,
          private bool $queue = false,
      ) {
          $this->dispatcher = $dispatcher;
          $this->applier = $applier;
      }

      /** @param array<string,mixed> $headers */
      public function ingest(string $gateway, string $rawBody, array $headers): WebhookIngestResult
      {
          if (!$this->gateways->supports($gateway, 'webhook')) {
              return new WebhookIngestResult(false, 404, "Unknown or webhook-incapable gateway '{$gateway}'.");
          }

          $driver = $this->gateways->webhookGateway($gateway);

          if (!$driver->verifyWebhookSignature($rawBody, $headers)) {
              return new WebhookIngestResult(false, 401, 'Invalid webhook signature.');
          }

          $event = $driver->parseWebhookEvent($rawBody, $headers);

          // -- INGEST (delivery idempotency) --
          $existing = $this->events->findByDeliveryKey($gateway, $event->deliveryKey());
          if ($existing !== null) {
              return $this->resumeExisting($existing, $event);
          }

          $uuid = $this->events->insertReceived($this->rowFor($gateway, 'webhook', $event, true));
          if ($uuid === null) {
              // Lost the unique race -> re-read and resume the winner's row.
              $winner = $this->events->findByDeliveryKey($gateway, $event->deliveryKey());
              if ($winner === null) {
                  return new WebhookIngestResult(false, 500, 'Ingest race could not be resolved.');
              }
              return $this->resumeExisting($winner, $event);
          }

          return $this->process($uuid, $event);
      }

      /**
       * Route a verify()-origin event through the same outbox (D6). Returns true if the
       * logical fact was dispatched (or already had been); false on insert failure.
       */
      public function recordVerifyEvent(PaymentProviderEventInterface $event): bool
      {
          $existing = $this->events->findByDeliveryKey($event->gateway(), $event->deliveryKey());
          if ($existing !== null) {
              $this->process((string) $existing['uuid'], $event);
              return true;
          }

          $uuid = $this->events->insertReceived($this->rowFor($event->gateway(), 'verify', $event, false));
          if ($uuid === null) {
              return false;
          }
          $this->process($uuid, $event);
          return true;
      }

      /** @param array<string,mixed> $existing */
      private function resumeExisting(array $existing, PaymentProviderEventInterface $event): WebhookIngestResult
      {
          $status = (string) ($existing['status'] ?? 'received');
          $uuid = (string) $existing['uuid'];

          if ($status === 'processed') {
              // Side effects durable; re-run DISPATCH stage (outbox).
              $this->dispatch($uuid, $event);
              return new WebhookIngestResult(true, 200, 'Re-dispatched.');
          }

          if ($status === 'failed') {
              $this->events->incrementAttempts($uuid);
              return $this->process($uuid, $event);
          }

          // received / processing: in-flight or stale crash -> resume without double-apply.
          return $this->process($uuid, $event);
      }

      private function process(string $uuid, PaymentProviderEventInterface $event): WebhookIngestResult
      {
          if ($this->queue) {
              // Queued path is wired in Task 3.8; sync fallthrough keeps zero-infra installs working.
              return $this->processSync($uuid, $event);
          }
          return $this->processSync($uuid, $event);
      }

      private function processSync(string $uuid, PaymentProviderEventInterface $event): WebhookIngestResult
      {
          try {
              $this->events->markProcessing($uuid);
              if ($this->applier !== null) {
                  ($this->applier)($event);
              }
              $this->events->markProcessed($uuid);
          } catch (\Throwable $e) {
              $this->events->markFailed($uuid, $e->getMessage());
              return new WebhookIngestResult(false, 500, 'Processing failed: ' . $e->getMessage());
          }

          $this->dispatch($uuid, $event);
          return new WebhookIngestResult(true, 200, 'ok');
      }

      private function dispatch(string $uuid, PaymentProviderEventInterface $event): void
      {
          // Unknown events are persisted but never dispatched to listeners.
          if ($event->type() === EventType::UNKNOWN) {
              return;
          }

          $gateway = $event->gateway();
          $logicalKey = $event->logicalEventKey();

          // Already terminal for this logical fact -> already-delivered duplicate. Mark this
          // delivery's own row dispatched (audit) without emitting.
          if ($this->events->isLogicalDispatched($gateway, $logicalKey)) {
              $this->events->markDispatched($uuid);
              return;
          }

          // Atomic claim: only the worker that flips `pending` -> `dispatching` (affected >= 1)
          // may emit. A concurrent worker matches 0 pending rows and skips -> exactly-once emit.
          if ($this->events->claimLogicalForDispatch($gateway, $logicalKey) < 1) {
              return; // lost the race; the winner emits + marks the group dispatched
          }

          ($this->dispatcher)(new PaymentProviderEvent($event));
          $this->events->markLogicalDispatched($gateway, $logicalKey);
      }

      /** @return array<string,mixed> */
      private function rowFor(string $gateway, string $source, PaymentProviderEventInterface $event, bool $signatureValid): array
      {
          $storeRaw = (bool) config($this->context, 'payvia.features.store_raw_payload', true);

          return [
              'gateway' => $gateway,
              'source' => $source,
              'provider_event_id' => $event->providerEventId(),
              'delivery_key' => $event->deliveryKey(),
              'logical_event_key' => $event->logicalEventKey(),
              'type' => $event->type(),
              'signature_valid' => $signatureValid,
              // Durable normalized snapshot -- always persisted; reconstruct() reads this back.
              'normalized_payload' => json_encode($event->normalized(), JSON_THROW_ON_ERROR),
              // Provider escape hatch -- gated by store_raw_payload.
              'raw_payload' => $storeRaw ? json_encode($event->raw(), JSON_THROW_ON_ERROR) : null,
          ];
      }
  }
  ```
- [ ] Run (expect PASS): `vendor/bin/phpunit --filter=WebhookIngestionTest`
- [ ] Commit: `git add src/Services/WebhookService.php tests/Integration/WebhookIngestionTest.php && git commit -m "feat(webhooks): ingest->apply->dispatch pipeline with two-key idempotency + outbox"`

### Task 3.7 -- Webhook controller + route (no auth, signature-verified)

**Files:**
- Create: `src/Controllers/WebhookController.php`
- Modify: `routes.php` (add `POST /payvia/webhooks/{gateway}`)
- Test: covered indirectly by `WebhookIngestionTest`; add a thin controller test mapping result -> HTTP status.

**Steps:**
- [ ] Create `src/Controllers/WebhookController.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Controllers;

  use Glueful\Bootstrap\ApplicationContext;
  use Glueful\Controllers\BaseController;
  use Glueful\Extensions\Payvia\Services\WebhookService;
  use Glueful\Http\Response;
  use Symfony\Component\HttpFoundation\Request;

  /**
   * Receives provider webhooks. No auth middleware -- authenticity is established by
   * per-gateway signature verification inside WebhookService, not the auth pipeline.
   */
  final class WebhookController extends BaseController
  {
      public function __construct(
          ApplicationContext $context,
          private ?WebhookService $webhooks = null,
      ) {
          parent::__construct($context);
          $this->webhooks = $this->webhooks ?? app($context, WebhookService::class);
      }

      public function handle(Request $request, string $gateway): Response
      {
          $rawBody = (string) $request->getContent();
          /** @var array<string,mixed> $headers */
          $headers = [];
          foreach ($request->headers->all() as $name => $values) {
              $headers[$name] = is_array($values) ? ($values[0] ?? '') : $values;
          }

          try {
              $result = $this->webhooks->ingest($gateway, $rawBody, $headers);
          } catch (\Throwable $e) {
              return Response::error('Webhook processing error: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
          }

          if ($result->accepted) {
              return Response::success(['status' => 'ok'], $result->message);
          }

          return Response::error($result->message, $result->httpStatus);
      }
  }
  ```
  > NOTE: confirm `Response::HTTP_INTERNAL_SERVER_ERROR` exists (it maps via `ErrorCodes`); if not, use the integer `500`. `Response::error($msg, $status)` is verified.
- [ ] Edit `routes.php`: add the import `use Glueful\Extensions\Payvia\Controllers\WebhookController;` and, inside the `/payvia` group, add:
  ```php
      /**
       * @route POST /payvia/webhooks/{gateway}
       * @summary Ingest Provider Webhook
       * @description
       *   Receives a provider webhook for {gateway} (paystack, ...). No auth: authenticity
       *   is established by per-gateway signature verification. Idempotent -- duplicate and
       *   cross-path deliveries dispatch the underlying business event at most once.
       * @tag Webhooks
       * @response 200 application/json "Event accepted"
       * @response 401 "Invalid signature"
       * @response 404 "Unknown or webhook-incapable gateway"
       */
      $router->post('/webhooks/{gateway}', [WebhookController::class, 'handle'])
          ->where('gateway', '[a-z0-9_]+');
  ```
  > NOTE: no `auth` middleware (per spec). Deliberately NO route-level `rate_limit` either: route middleware runs BEFORE ingest/signature verification, so a pre-verification throttle could reject a valid-signature provider delivery -- contradicting the "valid-signature events are never rejected for rate reasons" guarantee. Any abuse protection MUST be signature-aware and live INSIDE the pipeline, AFTER `verifyWebhookSignature` (e.g. throttle only invalid-signature sources by IP), so a verified event is never dropped. If such protection is wanted later, implement it in `WebhookService::ingest()` right after the signature check fails -- sketch:
  ```php
      // (optional, post-verification) inside ingest(), in the invalid-signature branch only:
      if (!$driver->verifyWebhookSignature($rawBody, $headers)) {
          // throttle/penalize the *source* here (e.g. per-IP counter) -- never the verified path
          return new WebhookIngestResult(false, 401, 'Invalid webhook signature.');
      }
  ```
- [ ] Run full suite (expect PASS, no regressions): `vendor/bin/phpunit`
- [ ] Commit: `git add src/Controllers/WebhookController.php routes.php && git commit -m "feat(webhooks): POST /payvia/webhooks/{gateway} (no auth, signature-verified)"`

### Task 3.8 -- Queued processing path + `ProcessWebhookJob`

**Files:**
- Create: `src/Jobs/ProcessWebhookJob.php`
- Modify: `src/Services/WebhookService.php` (`process()` enqueues when `queue=true`)
- Test: `tests/Integration/WebhookIngestionTest.php` (add a queued-path assertion using a fake QueueManager seam)

**Steps:**
- [ ] Add failing test to `WebhookIngestionTest` for the queued path. Because `QueueManager` push needs a connection, inject a queue closure seam instead (mirrors the dispatcher seam):
  ```php
      public function testQueuedPathPersistsReceivedThenEnqueuesWithoutDispatchingInline(): void
      {
          $pushed = [];
          $queueClosure = function (string $uuid) use (&$pushed): void { $pushed[] = $uuid; };

          $manager = new GatewayManager($this->context->getContainer(), $this->context);
          $manager->registerDriver('fake', FakeWebhookGateway::class);
          $this->bind(FakeWebhookGateway::class, $this->fake);

          $service = new WebhookService(
              $this->context, $manager, $this->events,
              fn (object $e) => $this->dispatched[] = $e,
              applier: null, queue: true, enqueue: $queueClosure,
          );

          $service->ingest('fake', $this->body('dq', 'RQ'), []);

          $row = $this->events->findByDeliveryKey('fake', 'dq');
          self::assertSame('received', $row['status']);     // not processed inline
          self::assertCount(0, $this->dispatched);           // not dispatched inline
          self::assertSame([$row['uuid']], $pushed);         // handed to the queue

          // Worker entrypoint reconstructs from the durable normalized_payload (the FakeWebhookGateway
          // raw is provider-shaped, NOT Payvia-normalized) and dispatches the right normalized data.
          $service->processStored((string) $row['uuid']);
          self::assertCount(1, $this->dispatched);
          self::assertSame('RQ', $this->dispatched[0]->event->normalized()['reference']);
          self::assertSame('dispatched', $this->events->findByDeliveryKey('fake', 'dq')['dispatch_status']);
      }
  ```
- [ ] Run (expect FAIL): `vendor/bin/phpunit --filter=testQueuedPathPersistsReceivedThenEnqueuesWithoutDispatchingInline`
- [ ] Edit `src/Services/WebhookService.php`: add an optional `enqueue` callable to the constructor and use it in `process()`:
  - Constructor: add `?callable $enqueue = null` parameter; store as `private $enqueue;` with `$this->enqueue = $enqueue;`. Add the property declaration `/** @var null|callable(string):void */ private $enqueue;`.
  - Replace `process()` body:
  ```php
      private function process(string $uuid, PaymentProviderEventInterface $event): WebhookIngestResult
      {
          if ($this->queue && $this->enqueue !== null) {
              // Persisted as `received`; the worker re-runs the side-effect + dispatch stages.
              ($this->enqueue)($uuid);
              return new WebhookIngestResult(true, 200, 'queued');
          }
          return $this->processSync($uuid, $event);
      }
  ```
  - Add a public method the worker calls to finish a persisted event (re-parse not needed; the row carries everything, but dispatch needs the VO -- so the worker reconstructs a minimal VO from the row). Add:
  ```php
      /**
       * Worker entrypoint: finish processing a persisted `received` event by uuid.
       * Rebuilds the normalized VO from the stored row, applies side effects, dispatches.
       */
      public function processStored(string $uuid): void
      {
          $row = $this->events->findByUuid($uuid);
          if ($row === null) {
              return;
          }
          $event = $this->reconstruct($row);
          $this->processSync($uuid, $event);
      }

      /** @param array<string,mixed> $row */
      private function reconstruct(array $row): PaymentProviderEventInterface
      {
          // Normalized snapshot is the durable source of truth for replay (always stored).
          $normalized = [];
          if (is_string($row['normalized_payload'] ?? null) && $row['normalized_payload'] !== '') {
              /** @var array<string,mixed> $normalized */
              $normalized = json_decode((string) $row['normalized_payload'], true) ?: [];
          }

          // Raw is the provider escape hatch -- only present when store_raw_payload was on.
          $raw = [];
          if (is_string($row['raw_payload'] ?? null) && $row['raw_payload'] !== '') {
              /** @var array<string,mixed> $raw */
              $raw = json_decode((string) $row['raw_payload'], true) ?: [];
          }

          return new \Glueful\Extensions\Payvia\Events\StoredProviderEvent(
              (string) $row['gateway'],
              (string) $row['type'],
              $row['provider_event_id'] !== null ? (string) $row['provider_event_id'] : null,
              (string) $row['delivery_key'],
              (string) $row['logical_event_key'],
              new \DateTimeImmutable((string) ($row['received_at'] ?? 'now')),
              $normalized,
              $raw,
          );
      }
  ```
  > NOTE: a stored row already carries the final `logical_event_key`/`delivery_key`, so the worker must NOT re-derive them. Create a tiny `StoredProviderEvent` VO that accepts a precomputed `logicalEventKey` (the `ProviderEvent::create` factory derives it, which would be wrong for replay). Add it next.
- [ ] Create `src/Events/StoredProviderEvent.php` (replay VO -- keys are taken verbatim from the persisted row):
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Events;

  use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;

  /**
   * VO reconstructed from a persisted provider_events row for queued/relay replay.
   * Unlike ProviderEvent::create(), the idempotency keys are taken verbatim (already
   * derived at ingest time) rather than re-derived.
   */
  final class StoredProviderEvent implements PaymentProviderEventInterface
  {
      /**
       * @param array<string,mixed> $normalized
       * @param array<string,mixed> $raw
       */
      public function __construct(
          private string $gateway,
          private string $type,
          private ?string $providerEventId,
          private string $deliveryKey,
          private string $logicalEventKey,
          private \DateTimeImmutable $occurredAt,
          private array $normalized,
          private array $raw,
      ) {
      }

      public function gateway(): string { return $this->gateway; }
      public function type(): string { return $this->type; }
      public function providerEventId(): ?string { return $this->providerEventId; }
      public function deliveryKey(): string { return $this->deliveryKey; }
      public function logicalEventKey(): string { return $this->logicalEventKey; }
      public function occurredAt(): \DateTimeImmutable { return $this->occurredAt; }
      public function normalized(): array { return $this->normalized; }
      public function raw(): array { return $this->raw; }
  }
  ```
- [ ] Create `src/Jobs/ProcessWebhookJob.php` (thin queue job that delegates to `WebhookService::processStored`):
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Jobs;

  use Glueful\Bootstrap\ApplicationContext;
  use Glueful\Extensions\Payvia\Services\WebhookService;
  use Glueful\Queue\Job;

  /**
   * Queued processing of a persisted provider_events row (D3 queued path).
   * Carries only the row uuid; the service rebuilds the VO from the stored row.
   */
  final class ProcessWebhookJob extends Job
  {
      public function handle(ApplicationContext $context): void
      {
          /** @var array<string,mixed> $data */
          $data = $this->getData();
          $uuid = (string) ($data['provider_event_uuid'] ?? '');
          if ($uuid === '') {
              return;
          }
          app($context, WebhookService::class)->processStored($uuid);
      }
  }
  ```
  > BLOCKER-CHECK for implementer: verify the concrete base for queue jobs. The framework ships `Glueful\Queue\Job` (abstract) and `Glueful\Queue\Contracts\JobInterface`. Confirm the actual handle signature the worker invokes (`handle(ApplicationContext)` vs `fire()`/`handle()` with payload via `getPayload()`). Match the framework's existing jobs in `src/Queue/Jobs/` (e.g. `SendNotification.php`) for the exact contract; adjust this class to that signature. If jobs cannot receive `ApplicationContext`, resolve the service via the container the worker provides. This is the one place to double-check against `src/Queue/Job.php` before finalizing.
- [ ] Wire the real enqueue closure in the service registration (Task 5.1) to `QueueManager::push(ProcessWebhookJob::class, ['provider_event_uuid' => $uuid], config('payvia.webhooks.queue_name'))`.
- [ ] Run (expect PASS): `vendor/bin/phpunit --filter=testQueuedPathPersistsReceivedThenEnqueuesWithoutDispatchingInline`
- [ ] Commit: `git add src/Jobs/ProcessWebhookJob.php src/Events/StoredProviderEvent.php src/Services/WebhookService.php tests/Integration/WebhookIngestionTest.php && git commit -m "feat(webhooks): queued processing path + ProcessWebhookJob + replay VO"`

### Task 3.9 -- Outbox relay command `payvia:relay-events`

**Files:**
- Create: `src/Console/RelayEventsCommand.php`
- Test: `tests/Integration/WebhookIngestionTest.php` (add a relay assertion) OR a dedicated `tests/Integration/RelayEventsTest.php`

**Steps:**
- [ ] Write failing test `tests/Integration/RelayEventsTest.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Tests\Integration;

  use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
  use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
  use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
  use Glueful\Extensions\Payvia\Services\WebhookService;
  use Glueful\Extensions\Payvia\GatewayManager;
  use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

  final class RelayEventsTest extends PayviaTestCase
  {
      public function testRelayReDispatchesProcessedPendingRows(): void
      {
          $this->runMigration(new CreateProviderEventsTable());
          $events = new ProviderEventRepository($this->connection);

          $uuid = $events->insertReceived([
              'gateway' => 'paystack', 'source' => 'webhook', 'provider_event_id' => null,
              'delivery_key' => 'd1', 'logical_event_key' => 'payment.succeeded:R1',
              'type' => 'payment.succeeded', 'signature_valid' => true,
              // Durable normalized snapshot is what relay reconstructs from (provider-shaped
              // raw is intentionally absent here to prove reconstruct() does NOT need it).
              'normalized_payload' => json_encode(['reference' => 'R1', 'status' => 'success']),
              'raw_payload' => null,
          ]);
          $events->markProcessing($uuid);
          $events->markProcessed($uuid);

          $dispatched = [];
          $manager = new GatewayManager($this->context->getContainer(), $this->context);
          $service = new WebhookService(
              $this->context, $manager, $events,
              function (object $e) use (&$dispatched) { $dispatched[] = $e; },
              applier: null, queue: false,
          );

          $relayed = $service->relayPending();
          self::assertSame(1, $relayed);
          self::assertCount(1, $dispatched);
          self::assertInstanceOf(PaymentProviderEvent::class, $dispatched[0]);
          // Reconstructed event carries the durable normalized snapshot (not the raw body).
          self::assertSame('R1', $dispatched[0]->event->normalized()['reference']);
          self::assertSame('dispatched', $events->findByUuid($uuid)['dispatch_status']);

          // Idempotent: a second relay does nothing.
          self::assertSame(0, $service->relayPending());
      }

      public function testConcurrentRelayForSameLogicalKeyEmitsExactlyOnce(): void
      {
          $this->runMigration(new CreateProviderEventsTable());
          $events = new ProviderEventRepository($this->connection);

          // Two distinct deliveries (different delivery_key) for the SAME logical fact, both
          // processed+pending -- two workers reaching DISPATCH "concurrently". The atomic
          // claim must let exactly one emit.
          foreach (['c-1', 'c-2'] as $dk) {
              $u = $events->insertReceived([
                  'gateway' => 'paystack', 'source' => 'webhook', 'provider_event_id' => null,
                  'delivery_key' => $dk, 'logical_event_key' => 'payment.succeeded:RC',
                  'type' => 'payment.succeeded', 'signature_valid' => true,
                  'normalized_payload' => json_encode(['reference' => 'RC', 'status' => 'success']),
                  'raw_payload' => null,
              ]);
              $events->markProcessing($u);
              $events->markProcessed($u);
          }

          $dispatched = [];
          $manager = new GatewayManager($this->context->getContainer(), $this->context);
          $service = new WebhookService(
              $this->context, $manager, $events,
              function (object $e) use (&$dispatched) { $dispatched[] = $e; },
              applier: null, queue: false,
          );

          self::assertSame(1, $service->relayPending());
          self::assertCount(1, $dispatched);
          self::assertTrue($events->isLogicalDispatched('paystack', 'payment.succeeded:RC'));
          self::assertSame('dispatched', $events->findByDeliveryKey('paystack', 'c-1')['dispatch_status']);
          self::assertSame('dispatched', $events->findByDeliveryKey('paystack', 'c-2')['dispatch_status']);
      }

      public function testCrashDuringDispatchIsRecoveredExactlyOnce(): void
      {
          $this->runMigration(new CreateProviderEventsTable());
          $events = new ProviderEventRepository($this->connection);

          // Row claimed (dispatch_status='dispatching') but never emitted, with an old
          // dispatch_claimed_at -> crash between claim and emit. Relay must re-claim + emit once.
          $u = $events->insertReceived([
              'gateway' => 'paystack', 'source' => 'webhook', 'provider_event_id' => null,
              'delivery_key' => 'c-crash', 'logical_event_key' => 'payment.succeeded:RX',
              'type' => 'payment.succeeded', 'signature_valid' => true,
              'normalized_payload' => json_encode(['reference' => 'RX', 'status' => 'success']),
              'raw_payload' => null,
          ]);
          $events->markProcessing($u);
          $events->markProcessed($u);
          $events->claimLogicalForDispatch('paystack', 'payment.succeeded:RX'); // claimed (dispatch_claimed_at=now)...
          $this->connection->table('provider_events')                          // ...then "crashed": backdate the claim
              ->where(['uuid' => $u])
              ->update(['dispatch_claimed_at' => $this->connection->getDriver()->formatDateTime(new \DateTimeImmutable('-1 hour'))]);

          $dispatched = [];
          $manager = new GatewayManager($this->context->getContainer(), $this->context);
          $service = new WebhookService(
              $this->context, $manager, $events,
              function (object $e) use (&$dispatched) { $dispatched[] = $e; },
              applier: null, queue: false,
          );

          self::assertSame(1, $service->relayPending());
          self::assertCount(1, $dispatched);
          self::assertSame('dispatched', $events->findByUuid($u)['dispatch_status']);
          // Second relay is a no-op.
          self::assertSame(0, $service->relayPending());
          self::assertCount(1, $dispatched);
      }
  }
  ```
- [ ] Run (expect FAIL): `vendor/bin/phpunit --filter=RelayEventsTest`
- [ ] Add `relayPending()` to `src/Services/WebhookService.php`:
  ```php
      /**
       * Re-dispatch rows that still need dispatching (outbox relay + crash recovery).
       * Candidates = processed+pending rows AND rows stuck in `dispatching` past the stale
       * window (claimed but never emitted, e.g. a crash between claim and emit). Each logical
       * group is (re-)claimed atomically so a concurrent relay/inline dispatch never
       * double-emits. Returns count emitted.
       */
      public function relayPending(int $limit = 100, int $staleSeconds = 300): int
      {
          $rows = $this->events->findDispatchable($limit, $staleSeconds);
          $count = 0;
          foreach ($rows as $row) {
              $uuid = (string) $row['uuid'];
              $event = $this->reconstruct($row);
              $gateway = $event->gateway();
              $logicalKey = $event->logicalEventKey();

              if ($event->type() === EventType::UNKNOWN) {
                  // No listener target -> drain this row out of the outbox.
                  $this->events->markDispatched($uuid);
                  continue;
              }

              // Already terminal -> already-delivered duplicate; drain without emitting.
              if ($this->events->isLogicalDispatched($gateway, $logicalKey)) {
                  $this->events->markDispatched($uuid);
                  continue;
              }

              // Atomically (re-)claim. A `pending` row is claimed via claimLogicalForDispatch;
              // a stale `dispatching` row (crash) is re-claimed via reclaimStaleDispatching.
              // Either path returning >= 1 means this relay owns the emit.
              $claimed = $this->events->claimLogicalForDispatch($gateway, $logicalKey);
              if ($claimed < 1) {
                  $claimed = $this->events->reclaimStaleDispatching($gateway, $logicalKey, $staleSeconds);
              }
              if ($claimed < 1) {
                  // Another worker holds a fresh claim -> skip (no double emit).
                  continue;
              }

              ($this->dispatcher)(new PaymentProviderEvent($event));
              $this->events->markLogicalDispatched($gateway, $logicalKey);
              $count++;
          }
          return $count;
      }
  ```
- [ ] Create `src/Console/RelayEventsCommand.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Console;

  use Glueful\Console\BaseCommand;
  use Glueful\Extensions\Payvia\Services\WebhookService;
  use Symfony\Component\Console\Attribute\AsCommand;
  use Symfony\Component\Console\Input\InputInterface;
  use Symfony\Component\Console\Input\InputOption;
  use Symfony\Component\Console\Output\OutputInterface;

  /**
   * Outbox relay: re-dispatch provider_events rows that are `processed` but still `pending`
   * (crash-after-processed recovery). Safe to run on a periodic sweep; fully idempotent.
   */
  #[AsCommand(name: 'payvia:relay-events', description: 'Re-dispatch processed-but-undispatched provider events (outbox relay)')]
  final class RelayEventsCommand extends BaseCommand
  {
      protected function configure(): void
      {
          $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max rows per run', '100');
      }

      protected function execute(InputInterface $input, OutputInterface $output): int
      {
          $limit = (int) ($input->getOption('limit') ?? 100);
          $ctx = $this->getContext();
          $service = app($ctx, WebhookService::class);

          $count = $service->relayPending($limit);
          $this->info("Relayed {$count} provider event(s).");

          return self::SUCCESS;
      }
  }
  ```
  > NOTE: confirm `BaseCommand` exposes `getContext()` and `info()` (used by tenancy commands `error()`/`getContext()` -- verified). If `info()` is absent, use `$output->writeln(...)`.
- [ ] Run (expect PASS): `vendor/bin/phpunit --filter=RelayEventsTest`
- [ ] Commit: `git add src/Console/RelayEventsCommand.php src/Services/WebhookService.php tests/Integration/RelayEventsTest.php && git commit -m "feat(webhooks): payvia:relay-events outbox relay"`

---

## Phase 4 -- Provider subscriptions (D7, D8, D9)

### Task 4.1 -- Migration: `gateway_subscriptions` table

**Files:**
- Create: `migrations/006_CreateGatewaySubscriptionsTable.php`
- Test: `tests/Integration/MigrationsTest.php` (add a case)

**Steps:**
- [ ] Add failing test to `MigrationsTest`:
  ```php
      public function testGatewaySubscriptionsTableShape(): void
      {
          $schema = $this->connection->getSchemaBuilder();
          (new \Glueful\Extensions\Payvia\Database\Migrations\CreateGatewaySubscriptionsTable())->up($schema);

          self::assertTrue($schema->hasTable('gateway_subscriptions'));
          foreach (['gateway_subscription_id', 'status', 'current_period_end', 'cancel_at_period_end', 'metadata', 'raw'] as $col) {
              self::assertTrue($schema->hasColumn('gateway_subscriptions', $col), "missing {$col}");
          }
          self::assertFalse($schema->hasColumn('gateway_subscriptions', 'tenant_uuid')); // D7: no tenant column
      }
  ```
- [ ] Run (expect FAIL): `vendor/bin/phpunit --filter=testGatewaySubscriptionsTableShape`
- [ ] Create `migrations/006_CreateGatewaySubscriptionsTable.php`:
  ```php
  <?php

  namespace Glueful\Extensions\Payvia\Database\Migrations;

  use Glueful\Database\Migrations\MigrationInterface;
  use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

  /**
   * Persisted provider-side recurring subscriptions (Workstream 4).
   *
   * Tenancy-agnostic (D7): no tenant_uuid column. The app's correlation id (typically a
   * tenant uuid) rides in provider metadata; mapping provider-sub -> tenant-sub is owned
   * by glueful/subscriptions. `raw` is gated by payvia.features.store_raw_payload at the
   * service layer (nullable here).
   */
  class CreateGatewaySubscriptionsTable implements MigrationInterface
  {
      public function up(SchemaBuilderInterface $schema): void
      {
          if ($schema->hasTable('gateway_subscriptions')) {
              return;
          }

          $schema->createTable('gateway_subscriptions', function ($table) {
              $table->bigInteger('id')->primary()->autoIncrement();
              $table->string('uuid', 12);

              $table->string('gateway', 50);
              $table->string('gateway_subscription_id', 191);
              $table->string('gateway_customer_id', 191)->nullable();
              $table->string('billing_plan_uuid', 12)->nullable();
              $table->string('gateway_price_id', 100)->nullable();

              $table->string('status', 20)->default('incomplete');
              $table->timestamp('current_period_start')->nullable();
              $table->timestamp('current_period_end')->nullable();
              $table->boolean('cancel_at_period_end')->default(false);
              $table->timestamp('canceled_at')->nullable();

              $table->json('metadata')->nullable();
              $table->json('raw')->nullable();

              $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
              $table->timestamp('updated_at')->nullable();

              $table->unique('uuid');
              $table->unique(['gateway', 'gateway_subscription_id']);
              $table->index('status');
              $table->index('billing_plan_uuid');
          });
      }

      public function down(SchemaBuilderInterface $schema): void
      {
          $schema->dropTableIfExists('gateway_subscriptions');
      }

      public function getDescription(): string
      {
          return 'Creates gateway_subscriptions (persisted provider subscriptions; tenancy-agnostic).';
      }
  }
  ```
- [ ] Run (expect PASS): `vendor/bin/phpunit --filter=testGatewaySubscriptionsTableShape`
- [ ] Commit: `git add migrations/006_CreateGatewaySubscriptionsTable.php tests/Integration/MigrationsTest.php && git commit -m "feat(subscriptions): gateway_subscriptions table (tenancy-agnostic)"`

### Task 4.2 -- `GatewaySubscriptionRepository` + contract

**Files:**
- Create: `src/Contracts/GatewaySubscriptionRepositoryInterface.php`
- Create: `src/Repositories/GatewaySubscriptionRepository.php`
- Test: `tests/Integration/GatewaySubscriptionServiceTest.php` (repo-level cases first)

**Steps:**
- [ ] Create `src/Contracts/GatewaySubscriptionRepositoryInterface.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Contracts;

  interface GatewaySubscriptionRepositoryInterface
  {
      /** @return array<string,mixed>|null */
      public function findByGatewayId(string $gateway, string $gatewaySubscriptionId): ?array;

      /**
       * Idempotent upsert keyed on (gateway, gateway_subscription_id). Returns the row uuid.
       *
       * @param array<string,mixed> $data
       */
      public function upsertByGatewayId(string $gateway, string $gatewaySubscriptionId, array $data): string;

      /** @return array<int,array<string,mixed>> */
      public function list(array $filters = []): array;
  }
  ```
- [ ] Write failing test `tests/Integration/GatewaySubscriptionServiceTest.php` (repo cases; service added next task):
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Tests\Integration;

  use Glueful\Extensions\Payvia\Database\Migrations\CreateGatewaySubscriptionsTable;
  use Glueful\Extensions\Payvia\Repositories\GatewaySubscriptionRepository;
  use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

  final class GatewaySubscriptionServiceTest extends PayviaTestCase
  {
      private GatewaySubscriptionRepository $repo;

      protected function setUp(): void
      {
          parent::setUp();
          $this->runMigration(new CreateGatewaySubscriptionsTable());
          $this->repo = new GatewaySubscriptionRepository($this->connection);
      }

      public function testUpsertInsertsThenUpdatesSameRow(): void
      {
          $u1 = $this->repo->upsertByGatewayId('paystack', 'SUB_1', ['status' => 'active', 'gateway_price_id' => 'PLN_x']);
          $u2 = $this->repo->upsertByGatewayId('paystack', 'SUB_1', ['status' => 'past_due']);

          self::assertSame($u1, $u2); // same logical row
          $row = $this->repo->findByGatewayId('paystack', 'SUB_1');
          self::assertSame('past_due', $row['status']);
          self::assertSame('PLN_x', $row['gateway_price_id']); // earlier field preserved
      }
  }
  ```
- [ ] Run (expect FAIL): `vendor/bin/phpunit --filter=testUpsertInsertsThenUpdatesSameRow`
- [ ] Create `src/Repositories/GatewaySubscriptionRepository.php` (find-then-insert/update, NOT SQLite `upsert`, to preserve untouched fields):
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Repositories;

  use Glueful\Extensions\Payvia\Contracts\GatewaySubscriptionRepositoryInterface;
  use Glueful\Helpers\Utils;
  use Glueful\Repository\BaseRepository;

  final class GatewaySubscriptionRepository extends BaseRepository implements GatewaySubscriptionRepositoryInterface
  {
      public function getTableName(): string
      {
          return 'gateway_subscriptions';
      }

      public function findByGatewayId(string $gateway, string $gatewaySubscriptionId): ?array
      {
          $rows = $this->db->table($this->getTableName())
              ->select(['*'])
              ->where(['gateway' => $gateway, 'gateway_subscription_id' => $gatewaySubscriptionId])
              ->limit(1)
              ->get();

          return $rows[0] ?? null;
      }

      public function upsertByGatewayId(string $gateway, string $gatewaySubscriptionId, array $data): string
      {
          // Never let callers overwrite the identity keys.
          unset($data['gateway'], $data['gateway_subscription_id'], $data['uuid'], $data['id']);

          $existing = $this->findByGatewayId($gateway, $gatewaySubscriptionId);
          if ($existing !== null) {
              if ($data !== []) {
                  $this->db->table($this->getTableName())
                      ->where(['gateway' => $gateway, 'gateway_subscription_id' => $gatewaySubscriptionId])
                      ->update(array_merge($data, [
                          'updated_at' => $this->db->getDriver()->formatDateTime(),
                      ]));
              }
              return (string) $existing['uuid'];
          }

          $uuid = Utils::generateNanoID(12);
          $this->db->table($this->getTableName())->insert(array_merge($data, [
              'uuid' => $uuid,
              'gateway' => $gateway,
              'gateway_subscription_id' => $gatewaySubscriptionId,
              'created_at' => $this->db->getDriver()->formatDateTime(),
          ]));

          return $uuid;
      }

      public function list(array $filters = []): array
      {
          $qb = $this->db->table($this->getTableName())
              ->select(['*'])
              ->orderBy(['created_at' => 'DESC']);

          if (isset($filters['gateway']) && is_string($filters['gateway']) && $filters['gateway'] !== '') {
              $qb = $qb->where('gateway', '=', $filters['gateway']);
          }
          if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
              $qb = $qb->where('status', '=', $filters['status']);
          }
          if (isset($filters['billing_plan_uuid']) && is_string($filters['billing_plan_uuid']) && $filters['billing_plan_uuid'] !== '') {
              $qb = $qb->where('billing_plan_uuid', '=', $filters['billing_plan_uuid']);
          }

          return $qb->get();
      }
  }
  ```
- [ ] Run (expect PASS): `vendor/bin/phpunit --filter=testUpsertInsertsThenUpdatesSameRow`
- [ ] Commit: `git add src/Contracts/GatewaySubscriptionRepositoryInterface.php src/Repositories/GatewaySubscriptionRepository.php tests/Integration/GatewaySubscriptionServiceTest.php && git commit -m "feat(subscriptions): GatewaySubscriptionRepository (idempotent upsert by gateway id)"`

### Task 4.3 -- `GatewaySubscriptionService` (webhook upsert + `reconcile()`)

**Files:**
- Create: `src/Services/GatewaySubscriptionService.php`
- Test: `tests/Integration/GatewaySubscriptionServiceTest.php` (add reconcile + apply cases)

**Steps:**
- [ ] Add failing tests to `GatewaySubscriptionServiceTest`:
  ```php
      public function testApplyNormalizedUpsertsFromEvent(): void
      {
          $manager = new \Glueful\Extensions\Payvia\GatewayManager($this->context->getContainer(), $this->context);
          $service = new \Glueful\Extensions\Payvia\Services\GatewaySubscriptionService(
              $this->context, $this->repo, $manager
          );

          $service->applyNormalized('paystack', [
              'gateway_subscription_id' => 'SUB_9',
              'gateway_price_id' => 'PLN_y',
              'status' => 'active',
          ], ['raw' => true]);

          $row = $this->repo->findByGatewayId('paystack', 'SUB_9');
          self::assertSame('active', $row['status']);
          self::assertNotNull($row['raw']); // store_raw_payload default true
      }

      public function testReconcilePullsAndUpserts(): void
      {
          $fake = new \Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway();
          $fake->fetchResult = [
              'subscription_code' => 'SUB_R',
              'status' => 'active',
              'plan' => ['plan_code' => 'PLN_r'],
          ];
          $this->bind(\Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway::class, $fake);

          $manager = new \Glueful\Extensions\Payvia\GatewayManager($this->context->getContainer(), $this->context);
          $manager->registerDriver('fake', \Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway::class);

          $service = new \Glueful\Extensions\Payvia\Services\GatewaySubscriptionService(
              $this->context, $this->repo, $manager
          );

          $uuid = $service->reconcile('fake', 'SUB_R');
          self::assertNotSame('', $uuid);
          $row = $this->repo->findByGatewayId('fake', 'SUB_R');
          self::assertSame('active', $row['status']);
      }
  ```
  > NOTE: `reconcile()` normalizes the raw provider object. For the fake we map the same Paystack-ish shape; the service's `normalizeRaw()` should handle `subscription_code`/`status`/`plan.plan_code`. Keep the normalizer minimal and provider-agnostic at the service layer (the driver-specific normalization already lives in `PaystackGateway::normalizeSubscription`; for `reconcile()` the service applies a small shared normalizer over the raw object).
- [ ] Run (expect FAIL): `vendor/bin/phpunit --filter=GatewaySubscriptionServiceTest`
- [ ] Create `src/Services/GatewaySubscriptionService.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Services;

  use Glueful\Bootstrap\ApplicationContext;
  use Glueful\Extensions\Payvia\Contracts\GatewaySubscriptionRepositoryInterface;
  use Glueful\Extensions\Payvia\GatewayManager;

  /**
   * Persisted provider subscriptions (Workstream 4 / D8).
   *
   * applyNormalized(): called from the webhook pipeline to upsert subscription state from a
   * normalized event. reconcile(): pulls authoritative provider state by id and upserts --
   * the recovery path for missed/out-of-order webhooks. Payvia owns no tenant mapping (D7).
   */
  final class GatewaySubscriptionService
  {
      public function __construct(
          private ApplicationContext $context,
          private GatewaySubscriptionRepositoryInterface $subscriptions,
          private GatewayManager $gateways,
      ) {
      }

      /**
       * Upsert from a normalized subscription subset (as produced by a gateway's
       * parseWebhookEvent normalization).
       *
       * @param array<string,mixed> $normalized
       * @param array<string,mixed> $options  ['raw' => array|bool] optional raw provider object
       */
      public function applyNormalized(string $gateway, array $normalized, array $options = []): string
      {
          $gatewaySubscriptionId = (string) ($normalized['gateway_subscription_id'] ?? '');
          if ($gatewaySubscriptionId === '') {
              throw new \InvalidArgumentException('Payvia: normalized subscription missing gateway_subscription_id.');
          }

          $row = [
              'gateway_customer_id' => $normalized['gateway_customer_id'] ?? null,
              'billing_plan_uuid' => $normalized['billing_plan_uuid'] ?? null,
              'gateway_price_id' => $normalized['gateway_price_id'] ?? null,
              'status' => (string) ($normalized['status'] ?? 'incomplete'),
              'current_period_start' => $normalized['current_period_start'] ?? null,
              'current_period_end' => $normalized['current_period_end'] ?? null,
              'cancel_at_period_end' => (bool) ($normalized['cancel_at_period_end'] ?? false),
              'canceled_at' => $normalized['canceled_at'] ?? null,
              'metadata' => isset($normalized['metadata']) ? json_encode($normalized['metadata'], JSON_THROW_ON_ERROR) : null,
          ];

          if ((bool) config($this->context, 'payvia.features.store_raw_payload', true)) {
              $raw = $options['raw'] ?? null;
              if (is_array($raw)) {
                  $row['raw'] = json_encode($raw, JSON_THROW_ON_ERROR);
              }
          }

          // Drop nulls so an upsert update never clobbers an existing value with null.
          $row = array_filter($row, static fn ($v) => $v !== null);

          return $this->subscriptions->upsertByGatewayId($gateway, $gatewaySubscriptionId, $row);
      }

      /**
       * Pull current provider state by id and upsert (D8). Returns the row uuid.
       */
      public function reconcile(string $gateway, string $gatewaySubscriptionId): string
      {
          $driver = $this->gateways->subscriptionGateway($gateway);
          $raw = $driver->fetchSubscription($gatewaySubscriptionId);

          $normalized = $this->normalizeRaw($raw);
          $normalized['gateway_subscription_id'] = $gatewaySubscriptionId;

          return $this->applyNormalized($gateway, $normalized, ['raw' => $raw]);
      }

      /**
       * Shared, provider-agnostic best-effort normalizer for reconcile() pulls. Driver-specific
       * webhook normalization lives in the driver; this covers the common provider-object shape.
       *
       * @param array<string,mixed> $raw
       * @return array<string,mixed>
       */
      private function normalizeRaw(array $raw): array
      {
          $status = (string) ($raw['status'] ?? 'incomplete');
          $map = [
              'active' => 'active',
              'trialing' => 'trialing',
              'attention' => 'past_due',
              'past_due' => 'past_due',
              'non-renewing' => 'active',
              'cancelled' => 'canceled',
              'canceled' => 'canceled',
              'complete' => 'canceled',
              'paused' => 'paused',
          ];

          return [
              'gateway_customer_id' => isset($raw['customer']['customer_code'])
                  ? (string) $raw['customer']['customer_code'] : ($raw['customer_id'] ?? null),
              'gateway_price_id' => $raw['plan']['plan_code'] ?? ($raw['gateway_price_id'] ?? null),
              'status' => $map[$status] ?? 'incomplete',
              'current_period_end' => $raw['next_payment_date'] ?? ($raw['current_period_end'] ?? null),
          ];
      }
  }
  ```
- [ ] Run (expect PASS): `vendor/bin/phpunit --filter=GatewaySubscriptionServiceTest`
- [ ] Commit: `git add src/Services/GatewaySubscriptionService.php tests/Integration/GatewaySubscriptionServiceTest.php && git commit -m "feat(subscriptions): GatewaySubscriptionService apply + reconcile()"`

### Task 4.4 -- Paystack `fetchSubscription`/`cancelSubscription`

**Files:**
- Modify: `src/Gateways/PaystackGateway.php` (implement the two `SubscriptionCapableGateway` methods)
- Test: `tests/Unit/PaystackWebhookSignatureTest.php` (add a fetch test with a mocked HTTP client)

**Steps:**
- [ ] Add failing test to `PaystackWebhookSignatureTest`:
  ```php
      public function testFetchSubscriptionReturnsRawData(): void
      {
          $secret = 'sk_test_secret';
          $http = $this->createMock(\Glueful\Http\Client::class);
          $response = $this->createMock(\Glueful\Http\Response::class);
          $response->method('getStatusCode')->willReturn(200);
          $response->method('toArray')->willReturn(['status' => true, 'data' => ['subscription_code' => 'SUB_1', 'status' => 'active']]);
          $http->method('get')->willReturn($response);

          \putenv('PAYVIA_PAYSTACK_WEBHOOK_SECRET=' . $secret);
          $ctx = $this->makeContext(); // helper that builds ApplicationContext (see signature test setup)
          $gw = new \Glueful\Extensions\Payvia\Gateways\PaystackGateway($http, $ctx);

          $data = $gw->fetchSubscription('SUB_1');
          self::assertSame('SUB_1', $data['subscription_code']);
          self::assertSame('active', $data['status']);
      }
  ```
  > NOTE: refactor the context-building lines from the earlier test into a small `makeContext()` helper in the test class to reuse here. Confirm `Glueful\Http\Response` exposes `toArray()`/`getStatusCode()` (the existing `PaystackGateway::verify` already calls both on the get() response).
- [ ] Run (expect FAIL): `vendor/bin/phpunit --filter=testFetchSubscriptionReturnsRawData`
- [ ] Edit `src/Gateways/PaystackGateway.php`: now add `SubscriptionCapableGateway` to the class declaration AND its two methods in this same step (so the interface and its implementations land together). Add the import:
  ```php
  use Glueful\Extensions\Payvia\Contracts\SubscriptionCapableGateway;
  ```
  Update the declaration to add the third interface:
  ```php
  final class PaystackGateway implements
      PaymentGatewayInterface,
      WebhookCapableGateway,
      SubscriptionCapableGateway
  ```
  Append both methods:
  ```php
      public function fetchSubscription(string $gatewaySubscriptionId): array
      {
          $config = (array) config($this->context, 'payvia.gateways.paystack', []);
          $secret = (string) ($config['secret_key'] ?? '');
          $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.paystack.co'), '/');
          $timeout = (int) ($config['timeout'] ?? 15);

          $response = $this->httpClient->get(
              $baseUrl . '/subscription/' . rawurlencode($gatewaySubscriptionId),
              [
                  'headers' => [
                      'Authorization' => 'Bearer ' . $secret,
                      'Accept' => 'application/json',
                  ],
                  'timeout' => $timeout,
              ]
          );

          /** @var array<string,mixed> $decoded */
          $decoded = $response->toArray();
          /** @var array<string,mixed> $data */
          $data = (array) ($decoded['data'] ?? []);

          return $data;
      }

      public function cancelSubscription(string $gatewaySubscriptionId, bool $atPeriodEnd = true): array
      {
          $config = (array) config($this->context, 'payvia.gateways.paystack', []);
          $secret = (string) ($config['secret_key'] ?? '');
          $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.paystack.co'), '/');
          $timeout = (int) ($config['timeout'] ?? 15);

          // Paystack disables a subscription via POST /subscription/disable with {code, token}.
          // The email token is part of the subscription object; callers pass it via fetch first.
          $sub = $this->fetchSubscription($gatewaySubscriptionId);
          $token = (string) ($sub['email_token'] ?? '');

          $response = $this->httpClient->post(
              $baseUrl . '/subscription/disable',
              [
                  'headers' => [
                      'Authorization' => 'Bearer ' . $secret,
                      'Accept' => 'application/json',
                      'Content-Type' => 'application/json',
                  ],
                  'json' => ['code' => $gatewaySubscriptionId, 'token' => $token],
                  'timeout' => $timeout,
              ]
          );

          /** @var array<string,mixed> $decoded */
          $decoded = $response->toArray();

          return [
              'gateway_subscription_id' => $gatewaySubscriptionId,
              'status' => 'canceled',
              'cancel_at_period_end' => $atPeriodEnd,
              'raw' => $decoded,
          ];
      }
  ```
  > NOTE: confirm `Glueful\Http\Client::post` accepts a `'json'` option (Symfony HttpClient convention; the existing `get()` uses `'headers'`/`'timeout'`). If the wrapper expects `'body'` instead, encode with `json_encode` and pass `'body'`. Verify against `src/Http/Client.php` before finalizing.
- [ ] Run (expect PASS): `vendor/bin/phpunit --filter=testFetchSubscriptionReturnsRawData`
- [ ] Commit: `git add src/Gateways/PaystackGateway.php tests/Unit/PaystackWebhookSignatureTest.php && git commit -m "feat(paystack): fetchSubscription + cancelSubscription (SubscriptionCapableGateway)"`

### Task 4.5 -- Wire subscription upserts into the webhook applier

**Files:**
- Modify: `src/Services/WebhookService.php` (no change to core; the applier is injected at registration -- documented here)
- Test: `tests/Integration/WebhookIngestionTest.php` (add a subscription-applier case)

**Steps:**
- [ ] Add failing test to `WebhookIngestionTest` proving the applier upserts a subscription:
  ```php
      public function testSubscriptionEventAppliesUpsert(): void
      {
          $this->runMigration(new \Glueful\Extensions\Payvia\Database\Migrations\CreateGatewaySubscriptionsTable());
          $subs = new \Glueful\Extensions\Payvia\Repositories\GatewaySubscriptionRepository($this->connection);
          $subService = new \Glueful\Extensions\Payvia\Services\GatewaySubscriptionService(
              $this->context, $subs,
              new GatewayManager($this->context->getContainer(), $this->context)
          );

          $manager = new GatewayManager($this->context->getContainer(), $this->context);
          $manager->registerDriver('fake', FakeWebhookGateway::class);
          $this->bind(FakeWebhookGateway::class, $this->fake);

          $applier = function (\Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface $e) use ($subService): void {
              if (str_starts_with($e->type(), 'subscription.')) {
                  $subService->applyNormalized($e->gateway(), $e->normalized(), ['raw' => $e->raw()]);
              }
          };

          $service = new WebhookService(
              $this->context, $manager, $this->events,
              fn (object $e) => $this->dispatched[] = $e,
              applier: $applier, queue: false,
          );

          $body = json_encode([
              'type' => 'subscription.created',
              'entity_id' => 'SUB_W',
              'delivery_key' => 'dsub',
              'normalized' => ['gateway_subscription_id' => 'SUB_W', 'status' => 'active', 'gateway_price_id' => 'PLN_w'],
          ]);
          $service->ingest('fake', $body, []);

          $row = $subs->findByGatewayId('fake', 'SUB_W');
          self::assertSame('active', $row['status']);
          self::assertCount(1, $this->dispatched);
      }
  ```
- [ ] Run (expect FAIL then PASS -- this exercises existing code; if it already passes, it documents the applier contract): `vendor/bin/phpunit --filter=testSubscriptionEventAppliesUpsert`
- [ ] If a gap surfaces (e.g. applier exceptions not marking `failed`), fix `WebhookService::processSync` accordingly (already wrapped in try/catch -> `markFailed`). No new code expected.
- [ ] Commit: `git add tests/Integration/WebhookIngestionTest.php && git commit -m "test(webhooks): subscription events upsert gateway_subscriptions via applier"`

---

## Phase 5 -- Wiring, verify-origin events, config & docs (D10, D11)

### Task 5.1 -- Config additions

**Files:**
- Modify: `config/payvia.php` (add `webhooks` block + `gateways.paystack.webhook_secret`)

**Steps:**
- [ ] Edit `config/payvia.php`: add `webhook_secret` to the paystack gateway block:
  ```php
          'paystack' => [
              'enabled' => (bool) env('PAYVIA_PAYSTACK_ENABLED', true),
              'driver' => 'paystack',
              'secret_key' => env('PAYVIA_PAYSTACK_SECRET_KEY', env('PAYSTACK_SECRET_KEY', null)),
              'webhook_secret' => env('PAYVIA_PAYSTACK_WEBHOOK_SECRET', env('PAYVIA_PAYSTACK_SECRET_KEY', env('PAYSTACK_SECRET_KEY', null))),
              'base_url' => env('PAYVIA_PAYSTACK_BASE_URL', 'https://api.paystack.co'),
              'timeout' => (int) env('PAYVIA_PAYSTACK_TIMEOUT', 15),
          ],
  ```
  and add a top-level `webhooks` block before `features`:
  ```php
      'webhooks' => [
          // D3: false = process synchronously (zero-infra); true = enqueue ProcessWebhookJob.
          'queue' => (bool) env('PAYVIA_WEBHOOKS_QUEUE', false),
          'queue_name' => env('PAYVIA_WEBHOOKS_QUEUE_NAME', null),
      ],
  ```
- [ ] Commit: `git add config/payvia.php && git commit -m "feat(config): webhooks.queue + paystack.webhook_secret"`

### Task 5.2 -- Service registrations + command discovery

**Files:**
- Modify: `src/PayviaServiceProvider.php` (`services()` add 5 entries + `WebhookService` factory wiring; `boot()` discover commands)

**Steps:**
- [ ] Edit `src/PayviaServiceProvider.php` `services()` -- add imports and entries for `ProviderEventRepositoryInterface`/`GatewaySubscriptionRepositoryInterface` (-> repos), `GatewaySubscriptionService`, `WebhookController`, and a `WebhookService` factory that injects the real dispatcher/applier/enqueue closures:
  ```php
              ProviderEventRepositoryInterface::class => [
                  'class' => ProviderEventRepository::class,
                  'shared' => true,
              ],
              GatewaySubscriptionRepositoryInterface::class => [
                  'class' => GatewaySubscriptionRepository::class,
                  'shared' => true,
              ],
              GatewaySubscriptionService::class => [
                  'class' => GatewaySubscriptionService::class,
                  'shared' => true,
                  'autowire' => true,
              ],
              WebhookController::class => [
                  'class' => WebhookController::class,
                  'shared' => true,
                  'autowire' => true,
              ],
              WebhookService::class => [
                  'factory' => [self::class, 'makeWebhookService'],
                  'shared' => true,
              ],
  ```
  > NOTE: confirm the container's DI definition shape supports a `'factory'` => `[Class, 'method']` entry (the framework container does; see how other extensions register factories). If `'factory'` is not supported, register a closure binding in `register()`/`boot()` via `$this->app->set(...)` instead. Verify the exact factory key the Glueful container expects before finalizing -- this is a wiring detail, not a logic blocker.
- [ ] Add a static factory `makeWebhookService` to `PayviaServiceProvider`:
  ```php
      public static function makeWebhookService(ContainerInterface $c): WebhookService
      {
          $context = $c->get(ApplicationContext::class);
          $manager = $c->get(GatewayManager::class);
          $events = $c->get(ProviderEventRepositoryInterface::class);
          $subscriptions = $c->get(GatewaySubscriptionService::class);
          $eventService = $c->get(\Glueful\Events\EventService::class);
          $queue = (bool) config($context, 'payvia.webhooks.queue', false);

          $dispatcher = static fn (object $event) => $eventService->dispatch($event);

          $applier = static function (\Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface $event) use ($subscriptions): void {
              if (str_starts_with($event->type(), 'subscription.')) {
                  $subscriptions->applyNormalized($event->gateway(), $event->normalized(), ['raw' => $event->raw()]);
              }
              // payment.*/invoice.* side effects reuse PaymentService/InvoiceService in a later
              // iteration; for v-next the durable record in provider_events + the dispatched
              // PaymentProviderEvent are the contract consumers depend on.
          };

          $enqueue = null;
          if ($queue) {
              $queueName = config($context, 'payvia.webhooks.queue_name', null);
              $qm = $c->get(\Glueful\Queue\QueueManager::class);
              $enqueue = static function (string $uuid) use ($qm, $queueName): void {
                  $qm->push(
                      \Glueful\Extensions\Payvia\Jobs\ProcessWebhookJob::class,
                      ['provider_event_uuid' => $uuid],
                      is_string($queueName) ? $queueName : null
                  );
              };
          }

          return new WebhookService($context, $manager, $events, $dispatcher, $applier, $queue, $enqueue);
      }
  ```
  Add `use Psr\Container\ContainerInterface;` and the new class imports at the top.
  > NOTE: confirm `ApplicationContext::class` and `EventService::class` and `QueueManager::class` are resolvable from the container in this extension context. If `ApplicationContext` is not a container id, capture it via `$this->app` in `boot()` and use a closure factory registered there instead of a static method. Verify resolvability; otherwise register via a `boot()` closure binding.
- [ ] Edit `boot()` -- after `loadMigrationsFrom(...)`, add command discovery:
  ```php
          try {
              $this->discoverCommands(
                  'Glueful\\Extensions\\Payvia\\Console',
                  __DIR__ . '/Console'
              );
          } catch (\Throwable $e) {
              error_log('[Payvia] Failed to discover commands: ' . $e->getMessage());
          }
  ```
- [ ] Run full suite (expect PASS): `vendor/bin/phpunit`
- [ ] Commit: `git add src/PayviaServiceProvider.php && git commit -m "feat(wiring): register webhook/subscription services, WebhookService factory, command discovery"`

### Task 5.3 -- Route verify-origin events through the outbox (D6)

**Files:**
- Modify: `src/Services/PaymentService.php` (after persisting, build a normalized event and call `WebhookService::recordVerifyEvent`)
- Test: `tests/Integration/PaymentServiceOutboxTest.php`

**Steps:**
- [ ] Write failing test `tests/Integration/PaymentServiceOutboxTest.php` proving a verify-origin success is deduped with a later webhook for the same reference:
  ```php
  <?php

  declare(strict_types=1);

  namespace Glueful\Extensions\Payvia\Tests\Integration;

  use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
  use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentsTable;
  use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
  use Glueful\Extensions\Payvia\Events\EventType;
  use Glueful\Extensions\Payvia\Events\ProviderEvent;
  use Glueful\Extensions\Payvia\GatewayManager;
  use Glueful\Extensions\Payvia\Repositories\PaymentRepository;
  use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
  use Glueful\Extensions\Payvia\Services\PaymentService;
  use Glueful\Extensions\Payvia\Services\WebhookService;
  use Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway;
  use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

  final class PaymentServiceOutboxTest extends PayviaTestCase
  {
      /** @var list<object> */
      private array $dispatched = [];
      private ProviderEventRepository $events;
      private WebhookService $webhooks;
      private GatewayManager $manager;
      private FakeWebhookGateway $fake;

      protected function setUp(): void
      {
          parent::setUp();
          $this->runMigration(new CreatePaymentsTable());
          $this->runMigration(new CreateProviderEventsTable());

          $this->events = new ProviderEventRepository($this->connection);

          $this->fake = new FakeWebhookGateway();
          $this->bind(FakeWebhookGateway::class, $this->fake);
          $this->manager = new GatewayManager($this->context->getContainer(), $this->context);
          $this->manager->registerDriver('fake', FakeWebhookGateway::class);

          $this->webhooks = new WebhookService(
              $this->context, $this->manager, $this->events,
              fn (object $e) => $this->dispatched[] = $e,
              applier: null, queue: false,
          );
      }

      /**
       * End-to-end through the REAL PaymentService: confirmAndRecord() drives the fake
       * gateway's verify(), persists the payment, and (D6) emits a verify-origin event into
       * the provider_events outbox. A later webhook for the same reference must converge on
       * the same logical_event_key and NOT re-dispatch.
       */
      public function testPaymentServiceEmitsVerifyOriginEventThroughOutbox(): void
      {
          // FakeWebhookGateway::verify() returns status=success for the given reference.
          $payments = new PaymentRepository($this->connection);
          $paymentService = new PaymentService($this->context, $payments, $this->manager, $this->webhooks);

          $result = $paymentService->confirmAndRecord('R1', 'fake', []);
          self::assertSame('success', $result['payment_status']);

          // A verify-origin row was recorded and dispatched exactly once.
          self::assertCount(1, $this->dispatched);
          $verifyRow = $this->events->findByDeliveryKey('fake', 'verify:R1'); // no provider id -> verify:{ref}
          self::assertNotNull($verifyRow);
          self::assertSame('verify', $verifyRow['source']);
          self::assertSame('payment.succeeded:R1', $verifyRow['logical_event_key']);
          self::assertSame('dispatched', $verifyRow['dispatch_status']);

          // Later webhook for the same R1 (different delivery key) -> logical dup -> no re-dispatch.
          $webhookBody = json_encode(['type' => 'payment.succeeded', 'entity_id' => 'R1', 'delivery_key' => 'evt_R1', 'normalized' => ['reference' => 'R1']]);
          $this->webhooks->ingest('fake', $webhookBody, []);
          self::assertCount(1, $this->dispatched);
          self::assertNotNull($this->events->findByDeliveryKey('fake', 'evt_R1'));
      }

      /** Direct unit-level check of the outbox seam (kept for fast, focused coverage). */
      public function testRecordVerifyEventDedupesWithWebhookForSameReference(): void
      {
          // verify()-origin event for reference R2 (native id 'txn_R2' -> its own delivery key).
          $verifyEvent = ProviderEvent::create('fake', EventType::PAYMENT_SUCCEEDED, 'txn_R2', 'txn_R2', 'R2', new \DateTimeImmutable(), ['reference' => 'R2'], []);
          $this->webhooks->recordVerifyEvent($verifyEvent);
          self::assertCount(1, $this->dispatched);

          // Later webhook for the same R2 (different delivery key) -> logical dup -> not re-dispatched.
          $webhookBody = json_encode(['type' => 'payment.succeeded', 'entity_id' => 'R2', 'delivery_key' => 'evt_R2', 'normalized' => ['reference' => 'R2']]);
          $this->webhooks->ingest('fake', $webhookBody, []);
          self::assertCount(1, $this->dispatched);

          // Both deliveries audited.
          self::assertNotNull($this->events->findByDeliveryKey('fake', 'txn_R2'));
          self::assertNotNull($this->events->findByDeliveryKey('fake', 'evt_R2'));
      }
  }
  ```
  > NOTE for implementer: this test depends on Task 5.3's `PaymentService` change (the `?WebhookService` constructor param + verify-origin emit). Order it after 5.3, or write the failing assertions first and let 5.3 turn them green. `FakeWebhookGateway::verify()` returns no `id`, so the verify-origin `deliveryKey` is `verify:{reference}` -- assert on that. Align `PaymentRepository`/`CreatePaymentsTable` construction with the real classes (both already exist in payvia 0.7.x).
- [ ] Run (expect FAIL -- `PaymentService` has no 4th `$webhooks` param yet, so it emits nothing): `vendor/bin/phpunit --filter=PaymentServiceOutboxTest`
- [ ] Edit `src/Services/PaymentService.php`: inject an optional `?WebhookService $webhooks = null` (constructor; keep nullable so existing callers/tests don't break) and, after the existing persist block (`$existing === null ? createPayment : updateByReference`), emit a verify-origin event when a `WebhookService` is available and the gateway has a normalizable result:
  ```php
          // D6: route the verify result through the provider_events outbox so a later webhook
          // for the same transaction dedupes on logical_event_key and dispatches exactly once.
          if ($this->webhooks !== null) {
              $type = $status === 'success' ? \Glueful\Extensions\Payvia\Events\EventType::PAYMENT_SUCCEEDED
                                            : \Glueful\Extensions\Payvia\Events\EventType::PAYMENT_FAILED;
              $deliveryKey = $providerId !== '' ? $providerId : ('verify:' . $reference);
              $event = \Glueful\Extensions\Payvia\Events\ProviderEvent::create(
                  gateway: $gatewayKey,
                  type: $type,
                  providerEventId: $providerId !== '' ? $providerId : null,
                  deliveryKey: $deliveryKey,
                  entityId: $reference,
                  occurredAt: new \DateTimeImmutable(),
                  normalized: ['reference' => $reference, 'status' => $status, 'amount' => $amount, 'currency' => $currency],
                  raw: is_array($verification['raw'] ?? null) ? $verification['raw'] : $verification,
              );
              try {
                  $this->webhooks->recordVerifyEvent($event);
              } catch (\Throwable $e) {
                  error_log('[Payvia] verify-origin event emit failed: ' . $e->getMessage());
              }
          }
  ```
  Add the constructor parameter:
  ```php
      public function __construct(
          ApplicationContext $context,
          private PaymentRepositoryInterface $payments,
          private GatewayManager $gateways,
          private ?\Glueful\Extensions\Payvia\Services\WebhookService $webhooks = null,
      ) {
          $this->context = $context;
      }
  ```
  > NOTE: `WebhookService` requires `provider_events` to exist. In zero-infra installs where migrations have not run, `recordVerifyEvent` will throw on insert -- the try/catch above swallows it so payment confirmation never regresses. The autowire container will inject `WebhookService` (now registered in 5.2); existing 0.7.x behavior is preserved when it is null.
- [ ] Run full suite (expect PASS): `vendor/bin/phpunit`
- [ ] Commit: `git add src/Services/PaymentService.php tests/Integration/PaymentServiceOutboxTest.php && git commit -m "feat(payments): emit verify-origin events through provider_events outbox (D6)"`

### Task 5.4 -- README Subscriptions contract docs

**Files:**
- Modify: `README.md`

**Steps:**
- [ ] In `README.md`, cover: (1) Payvia does not store entitlement catalogs; entitlements belong in `glueful/subscriptions`; (2) priced-plan linkage columns (`gateway`, `gateway_product_id`, `gateway_price_id`); (3) webhooks: `POST /payvia/webhooks/{gateway}`, provider webhook secrets, sync vs queued (`PAYVIA_WEBHOOKS_QUEUE`), `payvia:relay-events` sweep; (4) the four-point contract exposed to `glueful/subscriptions` from the spec: priced plans, normalized `PaymentProviderEvent` on the bus switching on `type()` and depending on `normalized()`, `gateway_subscriptions` reads + `reconcile()`, no tenancy coupling. Use ASCII only (`--`, `->`).
- [ ] Commit: `git add README.md && git commit -m "docs: provider events and Subscriptions contract"`

### Task 5.5 -- README + CHANGELOG + version bump to 1.0.0

**Files:**
- Modify: `README.md` (add Webhooks/Events/Subscriptions sections; remove `features` from plan examples; keep floor note)
- Modify: `CHANGELOG.md` (new `[1.0.0]` entry)
- Modify: `composer.json` (`extra.glueful.version` -> `1.0.0`; keep `extra.glueful.requires.glueful` `>=1.50.1` and `require-dev.glueful/framework` `^1.50.1`)

**Steps:**
- [ ] Edit `README.md`: remove `features` from any plan create/update example payloads; add short sections: "Webhooks" (endpoint, secret env, sync/queued, relay command), "Normalized provider events" (subscribe to `PaymentProviderEvent`, switch on `type()`, depend on `normalized()`, type vocabulary table), "Provider subscriptions" (`gateway_subscriptions`, `reconcile()`), and "Billing Plans and Entitlements" (Payvia does not store entitlement catalogs; entitlements belong in `glueful/subscriptions`).
- [ ] Edit `CHANGELOG.md`: replace the `## [Unreleased]` planned block with a `## [1.0.0] - 2026-06-10 -- Stable Payment Provider Surface` entry. Added: gateway-linkage columns; `PaymentProviderEventInterface`/`ProviderEvent`/`PaymentProviderEvent`/`EventType`; `WebhookCapableGateway`/`SubscriptionCapableGateway` + `GatewayManager` capability resolvers; `provider_events` (two-key idempotency + outbox) + repo; `POST /payvia/webhooks/{gateway}`; sync + queued processing + `ProcessWebhookJob`; `payvia:relay-events`; `gateway_subscriptions` + repo/service + `reconcile()`; Paystack webhook verify (HMAC SHA512) + subscription fetch/cancel; verify-origin events through the outbox (D6); config `webhooks.queue` + `paystack.webhook_secret`. Removed: `billing_plans.features`; entitlements move to `glueful/subscriptions`. Notes: framework floor unchanged (`>=1.50.1`).
- [ ] Edit `composer.json`: set `extra.glueful.version` to `1.0.0`. Leave `requires.glueful` and `require-dev` unchanged.
- [ ] Run full suite + static analysis (expect PASS): `vendor/bin/phpunit && vendor/bin/phpstan analyze src --level=5`
- [ ] Commit: `git add README.md CHANGELOG.md composer.json && git commit -m "docs+release: 1.0.0 -- stable payment provider surface"`

---

## Final verification checklist
- [ ] `vendor/bin/phpunit` -- all suites green.
- [ ] `vendor/bin/phpcs --standard=Squiz src` -- clean.
- [ ] `vendor/bin/phpstan analyze src --level=5` -- clean.
- [ ] All correctness-critical webhook scenarios are covered: duplicate delivery (`testExactRedeliveryDoesNotDoubleDispatch`), cross-path verify+webhook (`testCrossPathLogicalDuplicateDispatchesOnce` + `PaymentServiceOutboxTest`), crash-after-processed replay (`testReplayOfProcessedButUndispatchedRowDispatches` + `RelayEventsTest::testRelayReDispatchesProcessedPendingRows`), unique-delivery race (`testDuplicateDeliveryKeyInsertReturnsNull` + the unique-race resume branch in `ingest()`), concurrent logical-dispatch race -> exactly-once emit (`ProviderEventRepositoryTest::testAtomicClaimWinsOnceThenZero` + `RelayEventsTest::testConcurrentRelayForSameLogicalKeyEmitsExactlyOnce`), and crash-between-claim-and-emit recovery (`RelayEventsTest::testCrashDuringDispatchIsRecoveredExactlyOnce`).
- [ ] No billing-plan entitlement fields remain in schema, controller payloads, repository selects, route docs, README examples, or tests.
- [ ] Framework floor still `>=1.50.1`.

---

## Spec coverage map (every spec requirement -> task)
- W1 priced-plan reframe (D2): Tasks 1.1, 1.2. Entitlement-field removal (D1): 1.2, 1.3, 5.4.
- W2 webhook pipeline + `provider_events` (D3, D4): 3.3, 3.4 (table/repo), 3.6 (pipeline), 3.7 (route/controller), 3.8 (queued), 3.9 (relay).
- W3 normalized events (D5, D6): 2.1 (vocabulary), 2.2 (contract), 2.3 (VO + key derivation), 2.4 (BaseEvent), 5.3 (verify-origin D6).
- W4 provider subscriptions (D7, D8, D9): 3.1 (capabilities D9), 3.2 (manager), 4.1 (table D7), 4.2/4.3 (repo/service + reconcile D8), 4.4 (Paystack impl), 4.5 (webhook upsert).
- W5 wiring & docs (D10, D11): 5.1 (config), 5.2 (registrations), 5.4/5.5 (docs/CHANGELOG/version; D10 deferral noted; D11 floor unchanged).

## Notes / flags for the implementer (resolve while coding, not blockers)
1. **SQLite `upsert` is destructive** (`INSERT OR REPLACE` replaces all columns) -- the plan deliberately uses find-then-insert/update + DB unique constraints for both `provider_events` and `gateway_subscriptions`. Do not switch to `QueryBuilder::upsert` for these.
2. **Queue Job base contract** (Task 3.8) -- verify `Glueful\Queue\Job`'s actual handle signature against `src/Queue/Jobs/SendNotification.php` and match it. The queued path is fully exercised in tests via the injected `enqueue` closure, so the job class shape is the only thing to align.
3. **DI factory key** (Task 5.2) -- confirm the container accepts a `'factory' => [Class,'method']` definition; if not, register `WebhookService` via a closure binding in `boot()`. Pure wiring; logic is unaffected.
4. **`config()` plumbing in unit tests** (Tasks 3.5, 4.4) -- the Paystack signature/fetch tests must make `payvia.gateways.paystack.*` resolvable; use the framework config loader or a `'config'` container binding. Assertions are fixed; only the config-setup lines may need adjusting.
5. **`BaseRepository` constructor** -- the existing payvia repos extend it and use `$this->db`; confirm the constructor accepts the `Connection` (the harness passes `$this->connection`). If it resolves via context, construct through the container helper in tests.
