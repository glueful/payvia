<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

/**
 * Base for the two typed, fail-CLOSED outcomes of ensure-live initiation (payment-links spec
 * §2.1 / Rulings 5 and 6). Both mean the same operationally important thing: Payvia refused to
 * mint a replacement session because it could not prove the existing one dead, and the existing
 * intent is exactly as it was before the call.
 *
 * Typed so callers (Commerce's payment-link initiation) can map them onto honest "unavailable"
 * states instead of pattern-matching on message strings or -- far worse -- treating an
 * indeterminate provider as a licence to charge the customer again.
 */
abstract class EnsureLiveSessionException extends \RuntimeException
{
    public function __construct(
        public readonly string $gateway,
        public readonly string $payableType,
        public readonly string $payableId,
        public readonly string $reference,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
