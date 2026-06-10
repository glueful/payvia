<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Events;

use Glueful\Events\Contracts\BaseEvent;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;

final class PaymentProviderEvent extends BaseEvent
{
    public function __construct(public readonly PaymentProviderEventInterface $event)
    {
        parent::__construct();
    }
}
