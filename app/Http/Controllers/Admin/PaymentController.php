<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhook;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\GatewayResolver;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Services\PaymentTransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::query()
            ->with([
                'attempts',
            ])
            ->latest();

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if ($request->filled('search')) {

            $search = trim(
                (string) $request->input('search')
            );

            $query->where(function ($q) use ($search) {

                $q->where(
                    'merchant_reference',
                    'like',
                    '%' . $search . '%'
                )

                    ->orWhere(
                        'order_reference',
                        'like',
                        '%' . $search . '%'
                    )

                    ->orWhere(
                        'uuid',
                        'like',
                        '%' . $search . '%'
                    );
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        if ($request->filled('status')) {

            $query->where(
                'status',
                $request->string('status')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Currency
        |--------------------------------------------------------------------------
        */

        if ($request->filled('currency')) {

            $query->where(
                'currency',
                strtoupper(
                    (string) $request->input('currency')
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Payment Method
        |--------------------------------------------------------------------------
        */

        if ($request->filled('payment_method')) {

            $query->where(
                'payment_method',
                $request->string('payment_method')
            );
        }

        $payments = $query
            ->paginate(25)
            ->withQueryString();

        return view(
            'admin.payments.index',
            compact('payments')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Payment Details
    |--------------------------------------------------------------------------
    */

    public function show(Payment $payment)
    {
        $payment->load([
            'attempts',
            'transactions',
            'refunds',
        ]);

        /*
         * PaymentWebhook does not have paymentProvider().
         * It has provider().
         */
        $webhooks = PaymentWebhook::query()
            ->where('payment_id', $payment->id)
            ->latest()
            ->get();

        return view(
            'admin.payments.show',
            compact(
                'payment',
                'webhooks'
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Payment Refunds
    |--------------------------------------------------------------------------
    */

    public function refunds(Payment $payment)
    {
        $refunds = $payment->refunds()
            ->latest()
            ->paginate(25);

        return view(
            'admin.payments.refunds',
            compact(
                'payment',
                'refunds'
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY PAYMENT
    |--------------------------------------------------------------------------
    |
    | This method:
    |
    | 1. Finds the latest usable payment attempt.
    | 2. Resolves the configured gateway.
    | 3. Asks the provider for the current payment state.
    | 4. Validates amount/currency.
    | 5. Uses PaymentTransactionService to apply the financial result.
    |
    | We deliberately DO NOT directly update:
    |
    | payments.status
    | payments.amount_paid
    | payment_attempts.status
    |
    | PaymentTransactionService remains the single financial authority.
    |
    */

    public function verify(
        Payment $payment,
        \App\Payments\GatewayResolver $gatewayResolver,
        \App\Payments\Services\PaymentTransactionService $transactionService
    ) {
        try {
            $result = \DB::transaction(function () use ($payment, $gatewayResolver, $transactionService) {
                $lockedPayment = Payment::query()
                    ->whereKey($payment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                // Already paid — no need to verify again.
                if ($lockedPayment->status === \App\Enums\PaymentStatus::PAID) {
                    return [
                        'already_paid' => true,
                    ];
                }

                $attempt = $lockedPayment->attempts()
                    ->latest('created_at')
                    ->lockForUpdate()
                    ->first();

                if (!$attempt) {
                    throw new \RuntimeException(
                        'No payment attempt exists for this payment.'
                    );
                }

                $gateway = $gatewayResolver->resolve($attempt->provider);

                $verification = $gateway->getPaymentDetails($attempt);

                if (!$verification->success) {
                    throw new \RuntimeException(
                        $verification->message ?: 'Payment verification failed.'
                    );
                }

                $status = strtolower((string) $verification->status);

                if (!in_array($status, ['captured', 'paid', 'success', 'succeeded'], true)) {
                    throw new \RuntimeException(
                        'Provider payment is not successful. Current status: ' . $status
                    );
                }

                $providerPaymentId =
                    $verification->providerPaymentId
                    ?? data_get($verification->data, 'id')
                    ?? $attempt->provider_payment_id;

                if (!$providerPaymentId) {
                    throw new \RuntimeException(
                        'Provider payment ID was not returned by the gateway.'
                    );
                }

                $providerAmount = (int) (
                    data_get($verification->data, 'amount')
                    ?? $lockedPayment->amount
                );

                $providerCurrency = strtoupper((string) (
                    data_get($verification->data, 'currency')
                    ?? $lockedPayment->currency
                ));

                $paymentCurrency = strtoupper((string) $lockedPayment->currency);

                if ($providerAmount !== (int) $lockedPayment->amount) {
                    throw new \RuntimeException(
                        'Payment amount mismatch. Expected '
                        . $lockedPayment->amount
                        . ', provider returned '
                        . $providerAmount
                        . '.'
                    );
                }

                if ($providerCurrency !== $paymentCurrency) {
                    throw new \RuntimeException(
                        'Payment currency mismatch. Expected '
                        . $paymentCurrency
                        . ', provider returned '
                        . $providerCurrency
                        . '.'
                    );
                }

                $attempt->provider_payment_id = $providerPaymentId;
                $attempt->save();

                $transaction = $transactionService->recordSuccessfulSale(
                    payment: $lockedPayment,
                    attempt: $attempt,
                    providerTransactionId: $providerPaymentId,
                    amount: $providerAmount,
                    currency: $providerCurrency,
                    metadata: [
                        'source' => 'admin_verify',
                        'verified_at' => now()->toIso8601String(),
                        'gateway_status' => $verification->status,
                        'gateway_data' => $verification->data,
                    ],
                );

                return [
                    'already_paid' => false,
                    'transaction_id' => $transaction->id,
                ];
            });

            if ($result['already_paid']) {
                return redirect()
                    ->route('admin.payments.show', $payment)
                    ->with('info', 'Payment is already marked as paid.');
            }

            return redirect()
                ->route('admin.payments.show', $payment)
                ->with(
                    'success',
                    'Payment verified successfully and financial transaction recorded.'
                );

        } catch (\Throwable $e) {

            \Log::error('Admin payment verification failed', [
                'payment_id' => $payment->id,
                'payment_uuid' => $payment->uuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()
                ->route('admin.payments.show', $payment)
                ->with(
                    'error',
                    'Payment verification failed: ' . $e->getMessage()
                );
        }
    }
}