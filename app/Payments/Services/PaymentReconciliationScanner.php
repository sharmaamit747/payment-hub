<?php

namespace App\Payments\Services;

use App\Payments\Jobs\ReconcilePaymentAttemptJob;
use App\Models\PaymentAttempt;
use App\Payments\Enums\PaymentAttemptStatus;
use Illuminate\Support\Facades\Log;

class PaymentReconciliationScanner
{
    public function dispatchPendingAttempts(
        int $limit = 100
    ): int {
        $attempts = PaymentAttempt::query()
            ->whereIn(
                'status',
                [
                    PaymentAttemptStatus::UNKNOWN,
                    PaymentAttemptStatus::PENDING,
                ]
            )
            ->whereHas('payment', function ($query) {
                $query->whereNotIn(
                    'status',
                    [
                        'paid',
                        'refunded',
                    ]
                );
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $count = 0;

        foreach ($attempts as $attempt) {
            ReconcilePaymentAttemptJob::dispatch(
                $attempt->id
            )->onQueue('reconciliation');

            $count++;
        }

        Log::info('Payment reconciliation scan dispatched.', [
            'count' => $count,
        ]);

        return $count;
    }
}