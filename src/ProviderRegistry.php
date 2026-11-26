<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments;

use Argws\ElectronicPayments\Contracts\PaymentProvider;

final class ProviderRegistry
{
    /** @var array<string, \Closure(Account): PaymentProvider> */
    private array $factories = [];

    public function register(string $name, \Closure $factory): void
    {
        if ($name === '' || isset($this->factories[$name])) {
            throw new \InvalidArgumentException('Nome de provedor vazio ou já registrado.');
        }
        $this->factories[$name] = $factory;
    }

    public function forAccount(string $name, Account $account): PaymentProvider
    {
        $factory = $this->factories[$name] ?? throw new \DomainException('Provedor de pagamentos desconhecido.');
        return $factory($account);
    }
}
