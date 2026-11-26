<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments;

final readonly class ChargeRequest
{
    public function __construct(
        public string $customerId,
        public string $reference,
        public int $amountCents,
        public PaymentMethod $method,
        public \DateTimeImmutable $dueDate,
        public ?string $description = null,
        public int $installments = 1,
    ) {
        if (trim($reference) === '' || trim($customerId) === '' || $amountCents < 1 || $amountCents > 99999999999999) {
            throw new \InvalidArgumentException('Informe pagador, referência e valor positivo em centavos de BRL.');
        }
        if ($installments < 1 || $installments > $amountCents || ($installments > 1 && $method !== PaymentMethod::CreditCard)) {
            throw new \InvalidArgumentException('Parcelamento inválido para a cobrança.');
        }
    }
}
