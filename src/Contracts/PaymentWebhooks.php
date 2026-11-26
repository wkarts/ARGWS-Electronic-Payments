<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments\Contracts;

use Argws\ElectronicPayments\PaymentEvent;

interface PaymentWebhooks
{
    /** Retorna null para eventos autenticados que não sejam de cobrança. */
    public function webhook(string $body, #[\SensitiveParameter] string $providedToken, #[\SensitiveParameter] string $configuredSecret): ?PaymentEvent;
}
