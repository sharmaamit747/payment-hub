<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhook;
use App\Models\PaymentProvider;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'payments' => Payment::count(),

            'successful_payments' => Payment::query()
                ->where('status', 'paid')
                ->count(),

            'pending_payments' => Payment::query()
                ->whereIn('status', [
                    'created',
                    'initiated',
                    'processing',
                ])
                ->count(),

            'failed_payments' => Payment::query()
                ->where('status', 'failed')
                ->count(),

            'total_amount' => Payment::sum('amount'),

            'paid_amount' => Payment::sum('amount_paid'),

            'refunds' => PaymentRefund::count(),

            'refund_amount' => PaymentRefund::sum('amount'),

            'webhooks' => PaymentWebhook::count(),

            'failed_webhooks' => PaymentWebhook::query()
                ->where('status', 'failed')
                ->count(),

            'providers' => PaymentProvider::query()
                ->where('is_active', true)
                ->count(),
        ];

        $recentPayments = Payment::query()
            ->latest()
            ->limit(10)
            ->get();

        return view('admin.dashboard', compact(
            'stats',
            'recentPayments'
        ));
    }
}