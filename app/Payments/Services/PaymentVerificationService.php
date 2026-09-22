<?php

namespace App\Payments\Services;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\GatewayResolver;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentVerificationService
{
    public function __construct(
        private GatewayResolver $gatewayResolver,
        private PaymentTransactionService $transactionService
    ) {
    }

    public function verify(
        PaymentAttempt $attempt
    ): PaymentAttempt {
        /*
         * Load the latest state before contacting the provider.
         */
        $attempt = $attempt->fresh([
            'payment',
            'provider',
        ]);

        if (!$attempt) {
            throw new RuntimeException(
                'Payment attempt not found.'
            );
        }

        /*
         * A completed attempt does not need another verification.
         */
        if (
            $attempt->status ===
            PaymentAttemptStatus::SUCCEEDED
        ) {
            return $attempt;
        }

        $gateway = $this->gatewayResolver->resolve(
            $attempt->provider
        );

        /*
         * IMPORTANT:
         * Never hold a DB transaction while calling Razorpay.
         */
        $response = $gateway->verifyPayment($attempt);

        return DB::transaction(function () use ($attempt, $response) {
            $lockedAttempt = PaymentAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();

            $payment = Payment::query()
                ->whereKey($lockedAttempt->payment_id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Another process may have already completed it.
             */
            if (
                $lockedAttempt->status ===
                PaymentAttemptStatus::SUCCEEDED
            ) {
                return $lockedAttempt->fresh([
                    'payment',
                    'provider',
                ]);
            }

            $status = strtolower($response->status);

            /*
             * SUCCESS
             */
            if (
                $response->success ||
                in_array(
                    $status,
                    [
                        'captured',
                        'paid',
                        'succeeded',
                        'success',
                    ],
                    true
                )
            ) {
                if (!$response->providerPaymentId) {
                    throw new RuntimeException(
                        'Provider payment ID is missing from verification response.'
                    );
                }

                $amount = (int) (
                    $response->data['amount']
                    ?? $payment->amount
                );

                $currency = $response->data['currency']
                    ?? $payment->currency;

                $this->transactionService->recordSuccessfulSale(
                    payment: $payment,
                    attempt: $lockedAttempt,
                    providerTransactionId:
                    $response->providerPaymentId,
                    amount: $amount,
                    currency: $currency,
                    metadata: [
                        'source' => 'provider_verification',
                        'status' => $status,
                    ]
                );
            }

            /*
             * FAILED
             */ elseif (
                in_array(
                    $status,
                    [
                        'failed',
                        'failure',
                        'cancelled',
                        'canceled',
                    ],
                    true
                )
            ) {
                $lockedAttempt->status =
                    PaymentAttemptStatus::FAILED;

                $lockedAttempt->failure_reason =
                    $response->message;

                $lockedAttempt->completed_at = now();

                $lockedAttempt->save();

                if (
                    $payment->status !==
                    PaymentStatus::PAID
                ) {
                    $payment->status =
                        PaymentStatus::FAILED;

                    $payment->save();
                }
            }

            /*
             * Still pending/unknown.
             */ else {
                $lockedAttempt->status =
                    PaymentAttemptStatus::PENDING;

                $lockedAttempt->save();

                if (
                    $payment->status !==
                    PaymentStatus::PAID
                ) {
                    $payment->status =
                        PaymentStatus::PENDING;

                    $payment->save();
                }
            }

            return $lockedAttempt->fresh([
                'payment',
                'provider',
            ]);
        });
    }
}