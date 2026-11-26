<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments\Contracts;

use Argws\ElectronicPayments\CsvExport;

interface FinancialReports
{
    public function catalog(): array;
    /** Relatórios comuns: statement, balance e payments. */
    public function page(string $report, array $filters = []): array;
    public function export(string $report, array $filters = []): CsvExport;
}
