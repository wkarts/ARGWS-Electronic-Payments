<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments;

final readonly class Account
{
    public function __construct(
        public int $companyId,
        public ?int $branchId,
        public string $accountId,
        public Environment $environment,
    ) {
        if ($companyId < 1 || ($branchId !== null && $branchId < 1) || trim($accountId) === '') {
            throw new \InvalidArgumentException('Informe a empresa, a conta e uma filial válida ou null para Matriz.');
        }
    }

    public function authorize(Environment $issuerEnvironment): void
    {
        if ($issuerEnvironment === Environment::Sandbox && $this->environment === Environment::Production) {
            throw new \DomainException('O emitente em homologação só pode operar em sandbox.');
        }
    }
}
