<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Support;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Host seam for runtime-editable payment settings. A consuming application binds an
 * implementation to make a whitelisted subset of `payvia.*` keys editable at runtime
 * (an admin Payments screen backed by a settings table); with no binding every install
 * reads pure config/env exactly as before.
 *
 * Contract (identical to glueful/commerce's CommerceSettingsOverride):
 * - `value()` returns the RUNTIME value for a dotted config key, or null for "no override".
 * - NULL-NEVER-THROW: a missing store, an unknown key, absent tenant context, or any
 *   storage failure must resolve to null — config()/env stays the always-working fallback
 *   and a settings problem can never break payment processing or webhook verification.
 * - Secret values (secret_key/webhook_secret) are returned as PLAINTEXT; if the host
 *   stores them encrypted, decryption is the host implementation's job. Payvia never
 *   persists or logs what this seam returns.
 * - Consulted per read; implementations are expected to memoize per request.
 */
interface PayviaSettingsOverride
{
    public function value(ApplicationContext $context, string $key): ?string;
}
