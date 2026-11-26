<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';
}
