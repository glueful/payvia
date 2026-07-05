<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

use Glueful\Extensions\Contracts\Payments\PayableReference;

interface InitiationCapableGateway
{
    /**
     * Start a hosted payment flow for a payable.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed> At least reference and checkout_url when available.
     */
    public function initialize(PayableReference $payable, array $options = []): array;
}
