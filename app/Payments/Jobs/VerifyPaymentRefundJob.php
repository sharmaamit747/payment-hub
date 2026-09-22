<?php

namespace App\Payments\Jobs;

use App\Models\PaymentRefund;
use App\Payments\Enums\RefundStatus;
use App\Payments\Services\PaymentRefundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class VerifyPaymentRefundJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

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

    public function __construct(
        public int $refundId
    ) {
        $this->onQueue('payment-refunds');
    }

    public function handle(
        PaymentRefundService $refundService
    ): void {
        $refund = PaymentRefund::query()
            ->find($this->refundId);

        if (!$refund) {
            return;
        }

        /*
         * Only UNKNOWN refunds require provider verification.
         */
        if ($refund->status !== RefundStatus::UNKNOWN) {
            return;
        }

        try {
            $refundService->verifyUnknownRefund($refund);
        } catch (Throwable $e) {
            Log::warning(
                'Unknown payment refund verification failed.',
                [
                    'refund_id' => $refund->id,
                    'refund_uuid' => $refund->refund_uuid,
                    'payment_id' => $refund->payment_id,
                    'provider_id' => $refund->payment_provider_id,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]
            );

            throw $e;
        }
    }
}