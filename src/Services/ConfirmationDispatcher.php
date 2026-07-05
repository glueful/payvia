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

    public function dispatch(
        ApplicationContext $context,
        PayableReference $payable,
        PaymentConfirmation $confirmation
    ): void {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($payable->type)) {
                $handler->confirmed($context, $payable, $confirmation);
            }
        }

        $open = $this->intents->findOpen($context, $payable->type, $payable->id);
        if ($open !== null) {
            $this->intents->close($context, (string) $open['uuid'], $confirmation->reference);
        }
    }
}
