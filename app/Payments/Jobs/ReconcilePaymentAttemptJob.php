<?php

namespace App\Payments\Jobs;

use App\Models\PaymentAttempt;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Services\PaymentReconciliationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcilePaymentAttemptJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public int $attemptId
    ) {
        $this->onQueue('reconciliation');
    }

    public function backoff(): array
    {
        return [
            30,
            60,
            120,
            300,
            600,
        ];
    }

    public function handle(
        PaymentReconciliationService $service
    ): void {
        $attempt = PaymentAttempt::query()
            ->with([
                'payment',
                'provider',
            ])
            ->find($this->attemptId);

        if (!$attempt) {
            return;
        }

        /*
         * Only reconcile states that can legitimately be unresolved.
         */
        if (
            !in_array(
                $attempt->status,
                [
                    PaymentAttemptStatus::UNKNOWN,
                    PaymentAttemptStatus::PENDING,
                ],
                true
            )
        ) {
            return;
        }

        try {
            $reconciliation = $service->reconcile(
                $attempt
            );

            Log::info('Payment reconciliation completed.', [
                'attempt_id' => $attempt->id,
                'attempt_uuid' => $attempt->attempt_uuid,
                'reconciliation_uuid' =>
                    $reconciliation->reconciliation_uuid,
                'status' => $reconciliation->status->value,
                'mismatch_type' =>
                    $reconciliation->mismatch_type?->value,
            ]);
        } catch (Throwable $e) {
            Log::error('Payment reconciliation failed.', [
                'attempt_id' => $attempt->id,
                'attempt_uuid' => $attempt->attempt_uuid,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}