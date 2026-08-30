<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Finance\FinanceRecurringExpenseService;
use Illuminate\Console\Command;

class GenerateRecurringExpensesCommand extends Command
{
    protected $signature = 'finance:generate-recurring-expenses';

    protected $description = 'Generate due expenses for every active recurring expense/subscription, across every organization. Idempotent — safe to run multiple times.';

    public function handle(FinanceRecurringExpenseService $service): int
    {
        $summary = $service->generateDue();

        $this->info(sprintf(
            'Processed %d recurring expense(s): %d generated, %d already existed, %d period(s) advanced.',
            $summary['processed'],
            $summary['generated'],
            $summary['skipped_existing'],
            $summary['periods_advanced'],
        ));

        return self::SUCCESS;
    }
}
