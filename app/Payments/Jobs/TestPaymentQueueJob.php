<?php

namespace App\Payments\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class TestPaymentQueueJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Log::info('Payment queue test job executed.', [
            'executed_at' => now()->toDateTimeString(),
        ]);
    }
}