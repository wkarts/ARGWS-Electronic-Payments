<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments;

enum PaymentMethod: string
{
    case Pix = 'pix';
    case BankSlip = 'boleto';
    case CreditCard = 'credit_card';
}
