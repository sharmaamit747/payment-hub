<?php

use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentProcessingController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\PaymentVerificationController;
use App\Http\Controllers\Api\PaymentProviderHealthController;
use App\Http\Controllers\Api\PaymentReconciliationController;
use App\Http\Controllers\Api\PaymentRefundController;


Route::post('/payments', [
    PaymentController::class,
    'store',
]);

Route::post(
    '/payments/attempts/{attempt}/process',
    [PaymentProcessingController::class, 'store']
);

Route::post(
    '/webhooks/{provider}',
    PaymentWebhookController::class
);

Route::post(
    '/payments/attempts/{attempt}/verify',
    [PaymentVerificationController::class, 'store']
);

Route::get(
    '/payment-providers/health',
    [PaymentProviderHealthController::class, 'index']
);

Route::get(
    '/reconciliations',
    [PaymentReconciliationController::class, 'index']
);

Route::get(
    '/reconciliations/{reconciliation}',
    [PaymentReconciliationController::class, 'show']
);

Route::post(
    '/reconciliations/{reconciliation}/retry',
    [PaymentReconciliationController::class, 'retry']
);

Route::post(
    '/reconciliations/{reconciliation}/resolve',
    [PaymentReconciliationController::class, 'resolve']
);

Route::post(
    '/payments/{payment}/refunds',
    [PaymentRefundController::class, 'store']
);