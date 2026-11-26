<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments\Contracts;

use Argws\ElectronicPayments\ChargeId;

interface BankSlipInstructions
{
    public function bankSlipInstructions(ChargeId $charge): array;
}
