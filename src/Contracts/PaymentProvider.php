<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments\Contracts;

use Argws\ElectronicPayments\{Charge, ChargeId, ChargeRequest, PaymentMethod};

interface PaymentProvider
{
    /** @return list<PaymentMethod> */
    public function methods(): array;
    public function create(ChargeRequest $request): Charge;
    public function get(ChargeId $charge): Charge;
    /** @return list<Charge> */
    public function findByReference(string $reference, int $offset = 0, int $limit = 100): array;
    public function cancel(ChargeId $charge): void;
}
