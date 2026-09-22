<?php

namespace App\Payments\Jobs;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\GatewayResolver;
use App\Payments\Services\PaymentTransactionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class VerifyPaymentAttemptJob implements ShouldQueue
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
        public int $attemptId
    ) {
        $this->onQueue('payments');
    }

    public function handle(
        GatewayResolver $gatewayResolver,
        PaymentTransactionService $transactionService
    ): void {
        $claim = DB::transaction(function () {
            $attempt = PaymentAttempt::query()
                ->whereKey($this->attemptId)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Nothing to recover.
             */
            if (
                in_array($attempt->status, [
                    PaymentAttemptStatus::SUCCEEDED,
                    PaymentAttemptStatus::FAILED,
                    PaymentAttemptStatus::CANCELLED,
                    PaymentAttemptStatus::EXPIRED,
                ], true)
            ) {
                return null;
            }

            /*
             * Verification is only allowed for uncertain states.
             */
            if (
                !in_array($attempt->status, [
                    PaymentAttemptStatus::UNKNOWN,
                    PaymentAttemptStatus::PENDING,
                    PaymentAttemptStatus::PROCESSING,
                ], true)
            ) {
                return null;
            }

            $attempt->loadMissing([
                'payment',
                'provider',
            ]);

            return [
                'attempt_id' => $attempt->id,
                'payment_id' => $attempt->payment_id,
                'provider_id' => $attempt->payment_provider_id,
                'status' => $attempt->status,
            ];
        });

        if ($claim === null) {
            return;
        }

        $attempt = PaymentAttempt::query()
            ->with([
                'payment',
                'provider',
            ])
            ->findOrFail($claim['attempt_id']);

        $payment = $attempt->payment;
        $provider = $attempt->provider;

        try {
            $gateway = $gatewayResolver->resolve($provider);

            /*
             * If we don't have a provider payment ID,
             * verification cannot safely identify the payment.
             */
            if (!$attempt->provider_payment_id) {
                throw new \RuntimeException(
                    'Cannot verify payment attempt without provider payment ID.'
                );
            }

            $response = $gateway->verifyPayment($attempt);

            $status = strtolower(trim((string) $response->status));

            /*
             * Provider says payment succeeded.
             */
            if (
                $response->success
                && in_array($status, [
                    'paid',
                    'captured',
                    'succeeded',
                    'success',
                ], true)
            ) {
                if (!$response->providerPaymentId) {
                    throw new \RuntimeException(
                        'Provider reported successful payment without provider payment ID.'
                    );
                }

                $providerAmount = (int) data_get(
                    $response->data,
                    'amount',
                    $payment->amount
                );

                $providerCurrency = strtoupper(
                    (string) data_get(
                        $response->data,
                        'currency',
                        $payment->currency
                    )
                );

                if ($providerAmount !== (int) $payment->amount) {
                    $this->markManualReview(
                        $attempt->id,
                        'Provider payment amount does not match local payment.'
                    );

                    return;
                }

                if (
                    $providerCurrency !==
                    strtoupper((string) $payment->currency)
                ) {
                    $this->markManualReview(
                        $attempt->id,
                        'Provider payment currency does not match local payment.'
                    );

                    return;
                }

                DB::transaction(function () use ($attempt, $payment, $response, $providerAmount, $providerCurrency, $transactionService) {
                    $lockedAttempt = PaymentAttempt::query()
                        ->whereKey($attempt->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $lockedPayment = Payment::query()
                        ->whereKey($payment->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    /*
                     * Another webhook/job may already have recovered it.
                     */
                    if ($lockedPayment->status === PaymentStatus::PAID) {
                        return;
                    }

                    $providerTransactionId =
                        $response->providerPaymentId;

                    $transactionService->recordSuccessfulSale(
                        payment: $lockedPayment,
                        attempt: $lockedAttempt,
                        providerTransactionId: $providerTransactionId,
                        amount: $providerAmount,
                        currency: $providerCurrency,
                        metadata: [
                            'source' => 'payment_verification',
                            'verification' => true,
                            'provider_response' => $response->data,
                        ]
                    );
                });

                return;
            }

            /*
             * Provider explicitly says failed.
             */
            if (
                in_array($status, [
                    'failed',
                    'rejected',
                    'cancelled',
                    'canceled',
                ], true)
            ) {
                DB::transaction(function () use ($attempt) {
                    $lockedAttempt = PaymentAttempt::query()
                        ->whereKey($attempt->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $lockedPayment = Payment::query()
                        ->whereKey($attempt->payment_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    /*
                     * Never downgrade a paid payment.
                     */
                    if ($lockedPayment->status === PaymentStatus::PAID) {
                        return;
                    }

                    $lockedAttempt->status =
                        PaymentAttemptStatus::FAILED;

                    $lockedAttempt->completed_at = now();

                    $lockedAttempt->failure_reason =
                        'Provider verification reported payment failure.';

                    $lockedAttempt->save();

                    if (
                        $lockedPayment->status !==
                        PaymentStatus::FAILED
                    ) {
                        $lockedPayment->status =
                            PaymentStatus::FAILED;

                        $lockedPayment->save();
                    }
                });

                return;
            }

            /*
             * Provider says pending/created/authorized/etc.
             *
             * Keep the payment uncertain rather than guessing.
             */
            if (
                in_array($status, [
                    'pending',
                    'created',
                    'authorized',
                    'processing',
                ], true)
            ) {
                DB::transaction(function () use ($attempt) {
                    $lockedAttempt = PaymentAttempt::query()
                        ->whereKey($attempt->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $lockedAttempt->status =
                        PaymentAttemptStatus::PENDING;

                    $lockedAttempt->save();

                    $lockedPayment = Payment::query()
                        ->whereKey($attempt->payment_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (
                        $lockedPayment->status !==
                        PaymentStatus::PAID
                    ) {
                        $lockedPayment->status =
                            PaymentStatus::PENDING;

                        $lockedPayment->save();
                    }
                });

                return;
            }

            /*
             * Unknown provider response.
             *
             * Throw so Laravel retries the job.
             */
            throw new \RuntimeException(
                'Provider verification returned an inconclusive status.'
            );

        } catch (Throwable $e) {
            Log::warning(
                'Payment verification attempt failed.',
                [
                    'attempt_id' => $attempt->id,
                    'payment_id' => $payment->id,
                    'provider_id' => $provider->id,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]
            );

            throw $e;
        }
    }

    private function markManualReview(
        int $attemptId,
        string $reason
    ): void {
        DB::transaction(function () use ($attemptId, $reason) {
            $attempt = PaymentAttempt::query()
                ->whereKey($attemptId)
                ->lockForUpdate()
                ->firstOrFail();

            $payment = Payment::query()
                ->whereKey($attempt->payment_id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Do not automatically alter money/state when provider
             * data conflicts with our local record.
             */
            $attempt->status = PaymentAttemptStatus::UNKNOWN;
            $attempt->failure_reason = $reason;
            $attempt->save();

            if ($payment->status !== PaymentStatus::PAID) {
                $payment->status = PaymentStatus::UNKNOWN;
                $payment->save();
            }

            Log::error(
                'Payment moved to manual review because provider data mismatched local data.',
                [
                    'attempt_id' => $attempt->id,
                    'payment_id' => $payment->id,
                    'reason' => $reason,
                ]
            );
        });
    }
}