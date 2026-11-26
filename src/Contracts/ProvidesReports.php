<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments\Contracts;

interface ProvidesReports
{
    public function reports(): FinancialReports;
}
