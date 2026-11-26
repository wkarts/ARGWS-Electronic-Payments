<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments\Contracts;

use Argws\ElectronicPayments\ChargeId;

interface PixInstructions
{
    public function pixInstructions(ChargeId $charge): array;
}
