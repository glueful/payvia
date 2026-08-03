# Paystack checkout sandbox-proof fixtures

This directory holds the fixture JSON captured by
`payvia:checkout:sandbox-proof` (`src/Console/CheckoutSandboxProofCommand.php`) — a
MAINTAINER-RUN command, never executed in CI, that proves Paystack subscription
checkout against a **real Paystack sandbox account** and records exactly what
Paystack sends back. Task 3 of the workspace self-serve checkout program is
blocked until this directory contains fixtures and this README (or the fixtures
commit message) states which §3.1 mode — and amount shape — they prove.

## Prerequisites

Before running `php <console-entrypoint> payvia:checkout:sandbox-proof`:

1. **A Paystack sandbox (test-mode) account and secret key**, configured via
   `PAYVIA_PAYSTACK_SECRET_KEY` (or `PAYSTACK_SECRET_KEY`). Never point this
   command at a live-mode key.
2. **A publicly reachable webhook URL already configured on the Paystack
   dashboard**, pointing at this app's `/payvia/webhooks/paystack` endpoint
   (see `routes.php`). Declare that exact URL via `PAYVIA_PAYSTACK_WEBHOOK_URL`
   so the command can verify it before doing anything else. A tunnel
   (ngrok or similar) is normal for local sandbox runs — what matters is that
   the URL configured on Paystack's dashboard and the URL declared here are
   the same string.
3. **A webhook signature secret** present via `PAYVIA_PAYSTACK_WEBHOOK_SECRET`
   (or a fallback to the secret key, matching `PaystackGateway::verifyWebhookSignature()`).
4. **The provider-event ingestion path running**: the app (or its queue
   worker, if `payvia.webhooks.queue` is enabled) must actually be up and able
   to write to `provider_events` when Paystack's webhook lands. The command
   probes this itself (see "What preflight checks" below) but it cannot
   substitute for actually having the app/worker process running during the
   poll window.

If any of these are not true, the command's preflight **fails closed** —
nothing is created on Paystack, nothing is written to this directory, and no
network call happens beyond the checks needed to report why.

### What preflight checks

Three independent checks, all required:

- The configured public Paystack webhook URL's path is exactly
  `/payvia/webhooks/paystack` (`SandboxProofPreflight`).
- A webhook signature secret is present.
- The `provider_events` ingestion path is reachable — probed via
  `IngestionPathProbe` as **queue config sanity** (if `payvia.webhooks.queue`
  is enabled, a `QueueManager` must be bound, or accepted webhook rows would
  never be dispatched to a worker) **and** a lightweight reachability read
  against `provider_events` (not a "has a row landed recently" check — a
  fresh install with zero webhook traffic yet must still be able to run this
  command to produce its first one).

## What the command does

1. Records a start timestamp (UTC) and an exact reference, then creates a
   throwaway Paystack plan (`POST /plan`).
2. Calls `POST /transaction/initialize` twice against that plan — once
   **without** `amount`, once **with** — and prints both raw responses (kept
   in memory only; neither raw response is ever written to this directory).
3. Prints the checkout URL and waits for the maintainer to complete **one**
   hosted checkout using the reference from whichever `initialize` call
   actually returned a checkout URL.
4. Polls `provider_events` for post-start `charge.success` /
   `subscription.create` rows that correlate to this run (by reference, by
   the run's generated `origination_uuid` in `metadata`, or — for
   `subscription.create` specifically — by this run's single-use throwaway
   plan code).
5. Writes each matched row's raw payload through `FixtureProjector`'s CLOSED
   allowlist before it ever touches disk.

## Timeout and cleanup behavior

- Polling runs for `--poll-seconds` (default 600) in `--poll-interval`
  (default 5) second steps. If no matching row arrives before the deadline,
  the command exits with a failure and writes **nothing** — see the §3.1
  decision procedure below.
- The throwaway Paystack plan and both `transaction/initialize` references
  are single-use and named `sbxproof_*` / a `Payvia sandbox proof …` plan
  name specifically so they are unambiguous to find and delete from the
  Paystack sandbox dashboard afterwards. This command does not delete them
  itself (Paystack's API does not offer plan deletion); the maintainer should
  disable/archive the throwaway plan in the dashboard once fixtures are
  captured.
- Nothing this command does ever touches live-mode Paystack data, and no
  customer-identifying data it receives is ever written to disk — only
  `FixtureProjector`'s closed-allowlist projection is.

## Allowlist — what is (and is never) written

`FixtureProjector` is a closed allowlist, not denylist scrubbing: only
`event`, `reference`, `status`, `metadata.origination_uuid`, the proven
`subscription_code` / `plan_code` locations, and the minimum amount shape
(`{amount, currency}`) are ever copied out of a raw Paystack payload — by
reading exact, named paths, never by copying "everything except X". Customer
objects, names, emails, phones, addresses, `authorization` / `access_code` /
`signature` values, headers, and any other raw field are structurally
impossible to reach a committed fixture, proven by a hostile-payload unit
test in `tests/Unit/Console/CheckoutSandboxProofCommandTest.php`.

## §3.1 decision procedure

Apply this procedure to the captured fixtures (and the raw event dumps this
command prints while running) to decide — and record, in the fixtures commit
message — which mode this Paystack sandbox proves:

> metadata propagated to `subscription.create` ⇒ direct-correlation mode;
> else `charge.success` must carry reference/metadata AND nested
> `subscription_code` ⇒ two-event mode; neither ⇒ Paystack initiation stays
> unavailable and the Phase A release gate CANNOT pass.

- **Direct-correlation mode**: the captured `subscription.create` fixture's
  `metadata.origination_uuid` matches this run's generated value. A single
  webhook event carries everything needed to correlate the subscription back
  to the checkout that created it.
- **Two-event mode**: `subscription.create` does *not* carry the propagated
  metadata, but the captured `charge.success` fixture carries this run's
  `reference`/`metadata` *and* a nested `subscription_code`. Correlation
  requires combining both events.
- **Neither**: if the poll window elapses with no matching row, or the
  matched events satisfy neither condition above, Paystack initiation stays
  **unavailable** and the Phase A release gate **CANNOT pass** — do not
  commit partial/inconclusive fixtures as if they were proof.

The fixtures commit must state which of these three outcomes was reached,
and, for either passing mode, which amount shape (the `WITH amount` or
`WITHOUT amount` `transaction/initialize` call) actually produced a working
checkout.
