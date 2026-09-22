<?php

namespace App\Payments\Jobs;

use App\Payments\Services\PaymentRefundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPaymentRefundJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public int $refundId
    ) {
        $this->onQueue('payment-refunds');
    }

    public function backoff(): array
    {
        return [30, 60, 120, 300, 600];
    }

    public function handle(
        PaymentRefundService $service
    ): void {
        $service->processRefund($this->refundId);
    }
}