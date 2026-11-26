<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments;

final readonly class PaymentEvent
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public ChargeId $charge,
        public ChargeStatus $status,
        public ?string $reference,
    ) {}
}
