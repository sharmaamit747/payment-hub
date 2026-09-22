<?php

namespace App\Console\Commands;

use App\Payments\Services\PaymentReconciliationScanner;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile
                            {--limit=100 : Maximum attempts to scan}';

    protected $description =
        'Dispatch unresolved payment attempts for reconciliation';

    public function handle(
        PaymentReconciliationScanner $scanner
    ): int {
        $limit = max(
            1,
            min(
                1000,
                (int) $this->option('limit')
            )
        );

        $count = $scanner->dispatchPendingAttempts(
            $limit
        );

        $this->info(
            "Dispatched {$count} payment reconciliation job(s)."
        );

        return self::SUCCESS;
    }
}