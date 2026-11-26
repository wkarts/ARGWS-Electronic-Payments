<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments\Contracts;

use Argws\ElectronicPayments\{Charge, ChargeId};

interface Refunds
{
    public function refund(ChargeId $charge, ?int $amountCents = null, ?string $reason = null): Charge;
}
