<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmation;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmationHandler;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;

final class ConfirmationDispatcher
{
    /** @var list<PaymentConfirmationHandler> */
    private array $handlers;

    /** @param iterable<PaymentConfirmationHandler> $handlers */
    public function __construct(
        private PaymentIntentRepository $intents,
        iterable $handlers = [],
    ) {
        $this->handlers = array_values(is_array($handlers) ? $handlers : iterator_to_array($handlers));
    }

    /**
     * `$gateway` (payment-links Task 3) makes the intent lookup reference-addressable:
     * `(tenant_uuid, gateway, reference)` is the composite unique key Task 1's migration
     * added, so it resolves to the EXACT attempt row a webhook's reference belongs to --
     * never "whichever attempt happens to be open" for the payable. With supersession, a
     * payable can carry a retired attempt alongside its current open one, each under its
     * own reference; a webhook confirming an OLD reference must settle THAT row, leaving
     * the open attempt untouched. A reference matching no row at all (unmatched webhook)
     * is a no-op here, exactly as before this fix.
     */
    public function dispatch(
        ApplicationContext $context,
        PayableReference $payable,
        PaymentConfirmation $confirmation,
        string $gateway
    ): void {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($payable->type)) {
                $handler->confirmed($context, $payable, $confirmation);
            }
        }

        $row = $this->intents->findByReference($context, $gateway, $confirmation->reference);
        if ($row !== null) {
            $this->intents->settle($context, (string) $row['uuid']);
        }
    }
}
