<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

/**
 * The provider could not tell us -- definitively -- whether the hosted session already recorded
 * for this payable is still payable: an unparseable body, an unreachable API, or an expire/
 * re-fetch round trip that came back indeterminate.
 *
 * Ensure-live refuses here BY DESIGN (Ruling 5). Replacing a session whose real state is unknown
 * is precisely how a customer ends up with two live checkouts for one order, and the second
 * settlement is only discovered after the money has moved. The existing intent is untouched, so
 * the honest recoveries are: retry later (the provider may answer next time), settle offline, or
 * cancel with the operator's explicit risk acknowledgement.
 */
final class ProviderSessionStateUnknownException extends EnsureLiveSessionException
{
    public static function for(
        string $gateway,
        string $payableType,
        string $payableId,
        string $reference,
        ?\Throwable $previous = null,
    ): self {
        return new self(
            $gateway,
            $payableType,
            $payableId,
            $reference,
            sprintf(
                "Payvia could not confirm the state of the existing '%s' session for %s:%s "
                . '(reference %s); refusing to replace it.',
                $gateway,
                $payableType,
                $payableId,
                $reference !== '' ? $reference : 'unknown',
            ),
            $previous,
        );
    }
}
