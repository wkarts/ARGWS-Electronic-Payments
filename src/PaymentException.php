<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments;

final class PaymentException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $outcomeUnknown = false, int $httpStatus = 0)
    {
        parent::__construct($message, $httpStatus);
    }
}
