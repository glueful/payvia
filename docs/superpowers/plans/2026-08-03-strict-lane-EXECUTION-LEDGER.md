# SDD ledger — plan: /Users/michaeltawiahsowah/Sites/glueful/extensions/payvia/docs/superpowers/plans/2026-08-03-strict-payment-event-lane.md
Task 1: complete (commits ff4ced0..3a8c815, review clean)
Task 1: minor (deferred, Task-2 pointer): legacy markLogicalDispatched can clobber a lease-holder's row (no status/token filter) — Task 2 must route lease-path completion ONLY through completeLogicalDispatch and never mix APIs on one row
Task 1: minor (deferred): one token can stamp multiple physical rows sharing a logical key (mirrors legacy behavior; logical key is the identity)
Task 2: complete (commits 3a8c815..041f718, review clean — no findings)
Task 3: complete (commit 5b4fa5c, report: task-3-report.md)
Task 3: review found I1 (no three-lane ordering test — chargebacks-LAST unpinned) + I2 (WebhookService class docblock stale: still says two-step dispatcher). Fix round 1 dispatched.
Task 3: fix round 1 complete (commit 9005140) — added DisputeWebhookDispatchTest::testAllThreeLanesRunInExactOrderOrdinaryThenStrictThenChargebacks (RED proven by temporarily swapping the lane order, restored, GREEN) + rewrote WebhookService's class docblock to the three-step composition. Full suite 325/325, phpstan/phpcs clean.
Task 3: minor (deferred): makeWebhookService() container-tag guard never exercised through a real container (pre-existing convention gap)
Task 3: minor (deferred): FQCN-sort test uses 2 items + derived expectation rather than a literal order
Task 3: fix round 1/5 (2 addressed — three-lane ordering test w/ RED-by-swap proof + docblock rewrite; commits 5b4fa5c..9005140)
Task 3: complete (commits 041f718..9005140, review clean)
Task 4: complete (commits 9005140..ed31a2a, review clean; tag v2.4.0 local) — PHASE A (payvia 2.4.0) DONE
Task 5: implemented (subscriptions b6f6d4a); stranded payvia composer version field being committed + v2.4.0 retag (resumed implementer). Review after.
Task 5: complete (subscriptions c2a7c31..b6f6d4a + payvia 7874828/retag, review clean)
Task 5: minor (deferred): PayviaSubscriptionEventBridge docblock "ONLY class permitted to name payvia" now false (strict adapter names it) — one-line fix for the final wave
Task 5: minor (deferred): true payvia-absent boot path no longer exercisable in-process (payvia now permanent require-dev; only present-but-throwing covered) — note for Task 7/final review
Task 6: complete (commits b6f6d4a..9ce7da3, review clean — no findings)
Task 7: review APPROVED core (framework tags() claim TRUE — DSL providers need the 'tags' definition key; single-lane invariant verified; gate mutation-tested) but I1 (compile/boot mode-skew → silent zero lanes) + I2 (builder impure, bus≡none) enter fix round 1 (dispatched: boot-time skew guard w/ bus fallback + parameterized purity).
Task 7: minor (deferred): gate's StrictPaymentEventListener assertion effectively unfailable (bridge assertion carries the weight)
Task 7: minor (deferred): bus/none tag-wiring tests lack a positive control assertion
Task 7: minor (deferred): StrictLaneTagWiringTest temp-dir leak (glueful-strict-tag-wiring-*)
Task 7: CROSS-DOC FLAG: 'static tags() dead for DSL providers' must be fed back into the payvia design spec (Task 8 doc amendment)
Task 7: fix round 1/5 (I1+I2+fold-ins addressed — skew guard w/ CRITICAL log + bus fallback, purified two-arg builder, doc corrections; commits bfbc4a0..0feba5a)
Task 7: complete (commits 9ce7da3..0feba5a, review clean)
Task 8: implemented (subscriptions 2e5481e + v2.0.0 retag; payvia abae317 spec correction). Controller bookkeeping: moved v2.4.0 to abae317 (implementer skipped the instructed retag; docs-only delta). Review next.
Task 8: complete (subscriptions 0feba5a..2e5481e + payvia abae317; both retags done; review clean) — ALL 8 TASKS DONE
FINAL REVIEW: C1 (strict mode kills documented created-relink flow — T5×T7 interaction) + I2 (docs say at-most-once, contract is at-least-once) + I3 (payvia README recipe inert for DSL providers) + I4 (skew-fallback loss undocumented) + I5 (no real-factory composition test).
OWNER RULING (C1): widen supports() for subscription.created + non-empty gwSubId ONLY (no ownership proof for created); other five types keep local-mapping-or-marker; projector sole authority; foreign created retries accepted (future crypto correlation token); tests must cover legacy tenant-metadata relink via strict lane + retryable no-local-row case. Fix wave dispatched.
FINAL FIX WAVE: all 6 addressed (subscriptions 82aafd1, payvia 0348c55; both tags re-pointed), re-review clean. BACKLOG: cryptographic correlation token (or universal ownership marker) to eliminate foreign created-event retry noise — the only clean fix, deliberately deferred per owner ruling. NOTE: this program RESOLVES the 2.0-program cross-repo flag (payvia dispatch vs dispatchOrFail). PROGRAM COMPLETE.
