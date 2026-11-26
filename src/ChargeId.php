<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments;

final readonly class ChargeId
{
    public function __construct(public Account $account, public string $provider, public string $id)
    {
        if (trim($provider) === '' || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/', $id)) {
            throw new \InvalidArgumentException('Identificador de cobrança inválido.');
        }
    }

    public function forAccount(Account $account, string $provider): string
    {
        if ($this->account != $account || $this->provider !== $provider) {
            throw new \DomainException('Esta cobrança pertence a outra conexão de pagamento.');
        }
        return $this->id;
    }
}
