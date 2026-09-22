<?php

use App\Payments\Services\PaymentReconciliationScanner;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    app(PaymentReconciliationScanner::class)->scan();
})
    ->name('payment-reconciliation')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();
