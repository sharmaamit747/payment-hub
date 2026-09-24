<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\ProviderController;
use App\Http\Controllers\Admin\WebhookController;
use App\Http\Controllers\Admin\ReconciliationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('admin.login');
});

/*
|--------------------------------------------------------------------------
| Admin Authentication
|--------------------------------------------------------------------------
*/

Route::name('admin.')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Admin Authentication
        |--------------------------------------------------------------------------
        */

        Route::middleware('guest')->group(function () {
            Route::get('/login', [AuthController::class, 'showLogin'])
                ->name('login');

            Route::post('/login', [AuthController::class, 'login'])
                ->name('login.submit');
        });

        Route::post('/logout', [AuthController::class, 'logout'])
            ->middleware('auth')
            ->name('logout');


        /*
        |--------------------------------------------------------------------------
        | Protected Admin Area
        |--------------------------------------------------------------------------
        */

        Route::middleware(['auth', 'admin'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Dashboard
            |--------------------------------------------------------------------------
            */

            Route::get('/admin/dashboard', [DashboardController::class, 'index'])
                ->middleware('permission:system.view')
                ->name('dashboard');


            /*
            |--------------------------------------------------------------------------
            | Payments
            |--------------------------------------------------------------------------
            */

            Route::get('/payments', [PaymentController::class, 'index'])
                ->middleware('permission:payments.view')
                ->name('payments.index');

            Route::get('/payments/{payment}', [PaymentController::class, 'show'])
                ->middleware('permission:payments.view')
                ->name('payments.show');

            Route::post('/payments/{payment}/verify', [PaymentController::class, 'verify'])
                ->middleware('permission:payments.view')
                ->name('payments.verify');

            Route::get('/payments/{payment}/refunds', [PaymentController::class, 'refunds'])
                ->middleware('permission:payments.view')
                ->name('payments.refunds');


            /*
            |--------------------------------------------------------------------------
            | Providers
            |--------------------------------------------------------------------------
            */

            Route::get('/providers', [ProviderController::class, 'index'])
                ->middleware('permission:providers.view')
                ->name('providers.index');

            Route::get('/providers/{provider}', [ProviderController::class, 'show'])
                ->middleware('permission:providers.view')
                ->name('providers.show');


            /*
            |--------------------------------------------------------------------------
            | Reconciliation
            |--------------------------------------------------------------------------
            */

            Route::get('/reconciliation', [ReconciliationController::class, 'index'])
                ->middleware('permission:reconciliation.view')
                ->name('reconciliation.index');

            Route::get('/reconciliation/{reconciliation}', [ReconciliationController::class, 'show'])
                ->middleware('permission:reconciliation.view')
                ->name('reconciliation.show');


            /*
            |--------------------------------------------------------------------------
            | Webhooks
            |--------------------------------------------------------------------------
            */

            Route::get('/webhooks', [WebhookController::class, 'index'])
                ->middleware('permission:webhooks.view')
                ->name('webhooks.index');

            Route::get('/webhooks/{webhook}', [WebhookController::class, 'show'])
                ->middleware('permission:webhooks.view')
                ->name('webhooks.show');
        });
    });