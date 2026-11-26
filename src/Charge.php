<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments;

final readonly class Charge
{
    public function __construct(
        public ChargeId $id,
        public ?string $reference,
        public ChargeStatus $status,
        public string $providerStatus,
        public int $amountCents,
        public ?string $checkoutUrl,
        public ?string $bankSlipUrl,
        public ?string $installmentId,
    ) {}
}
