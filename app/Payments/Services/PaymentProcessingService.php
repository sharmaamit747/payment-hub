<?php

namespace App\Payments\Services;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Exceptions\GatewayException;
use App\Payments\Jobs\VerifyPaymentAttemptJob;
use App\Payments\GatewayResolver;
use Illuminate\Support\Facades\DB;
use Throwable;

class PaymentProcessingService
{
    public function __construct(
        private GatewayResolver $gatewayResolver,
        private PaymentProviderCircuitBreaker $circuitBreaker
    ) {
    }

    public function process(PaymentAttempt $attempt): PaymentAttempt
    {
        /*
         * Lock the attempt so two workers cannot process it
         * simultaneously.
         */
        $attempt = DB::transaction(function () use ($attempt) {

            $lockedAttempt = PaymentAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->with(['payment', 'provider'])
                ->firstOrFail();

            /*
             * Never submit an already successful attempt again.
             */
            if ($lockedAttempt->status === PaymentAttemptStatus::SUCCEEDED) {
                return $lockedAttempt;
            }

            /*
             * Prevent processing of cancelled/expired attempts.
             */
            if (
                in_array(
                    $lockedAttempt->status,
                    [
                        PaymentAttemptStatus::CANCELLED,
                        PaymentAttemptStatus::EXPIRED,
                    ],
                    true
                )
            ) {
                throw new GatewayException(
                    "Payment attempt [{$lockedAttempt->attempt_uuid}] "
                    . "cannot be processed from status "
                    . "[{$lockedAttempt->status->value}]."
                );
            }

            /*
             * Move attempt to initiated.
             */
            if ($lockedAttempt->status === PaymentAttemptStatus::CREATED) {
                $lockedAttempt->status =
                    PaymentAttemptStatus::INITIATED;

                $lockedAttempt->started_at = now();

                $lockedAttempt->save();
            }

            /*
             * Move payment to initiated.
             */
            $payment = Payment::query()
                ->whereKey($lockedAttempt->payment_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->status === PaymentStatus::CREATED) {
                $payment->status = PaymentStatus::INITIATED;
                $payment->save();
            }

            return $lockedAttempt->fresh([
                'payment',
                'provider',
            ]);
        });

        /*
         * IMPORTANT:
         *
         * Never keep a database transaction open while calling
         * an external payment provider.
         */
        try {

            $provider = $attempt->provider;

            $payment = $attempt->payment;

            $gateway = $this->gatewayResolver->resolve(
                $provider
            );

            $this->circuitBreaker->beforeRequest($provider);

            try {
                $response = $gateway->createPayment(
                    $payment,
                    $attempt
                );

                $this->circuitBreaker->recordSuccess($provider);

            } catch (\Throwable $e) {

                $this->circuitBreaker->recordFailure(
                    $provider,
                    $e
                );

                throw $e;
            }

            return DB::transaction(function () use ($attempt, $response) {

                $lockedAttempt = PaymentAttempt::query()
                    ->whereKey($attempt->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * Another worker may have completed it while the
                 * external request was running.
                 */
                if (
                    $lockedAttempt->status ===
                    PaymentAttemptStatus::SUCCEEDED
                ) {
                    return $lockedAttempt;
                }

                if ($response->success) {

                    $lockedAttempt->provider_payment_id =
                        $response->providerPaymentId;

                    $lockedAttempt->provider_order_id =
                        $response->providerOrderId;

                    $lockedAttempt->response_payload =
                        $response->data;

                    $lockedAttempt->status =
                        $this->mapSuccessStatus(
                            $response->status
                        );

                    $lockedAttempt->completed_at = now();

                    $lockedAttempt->save();

                    $payment = Payment::query()
                        ->whereKey($lockedAttempt->payment_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $payment->status =
                        PaymentStatus::INITIATED;

                    $payment->save();

                } else {

                    $lockedAttempt->response_payload =
                        $response->data;

                    $lockedAttempt->failure_reason =
                        $response->message;

                    $lockedAttempt->status =
                        PaymentAttemptStatus::FAILED;

                    $lockedAttempt->completed_at = now();

                    $lockedAttempt->save();

                    $payment = Payment::query()
                        ->whereKey($lockedAttempt->payment_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $payment->status =
                        PaymentStatus::FAILED;

                    $payment->save();
                }

                return $lockedAttempt->fresh([
                    'payment',
                    'provider',
                ]);
            });

        } catch (Throwable $e) {

            /*
             * Do NOT automatically retry here.
             *
             * A timeout can mean Razorpay created the order but
             * our application never received the response.
             *
             * Such an UNKNOWN case will be handled by verification
             * / recovery logic.
             */

            DB::transaction(function () use ($attempt, $e) {

                $lockedAttempt = PaymentAttempt::query()
                    ->whereKey($attempt->id)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedAttempt) {
                    return;
                }

                /*
                 * Don't overwrite a successful result.
                 */
                if (
                    $lockedAttempt->status ===
                    PaymentAttemptStatus::SUCCEEDED
                ) {
                    return;
                }

                $lockedAttempt->failure_code =
                    class_basename($e);

                $lockedAttempt->failure_reason =
                    mb_substr($e->getMessage(), 0, 500);

                $lockedAttempt->status =
                    PaymentAttemptStatus::UNKNOWN;

                $lockedAttempt->completed_at = now();

                $lockedAttempt->save();

                $payment = Payment::query()
                    ->whereKey($lockedAttempt->payment_id)
                    ->lockForUpdate()
                    ->first();

                if (
                    $payment &&
                    $payment->status !== PaymentStatus::PAID
                ) {
                    $payment->status =
                        PaymentStatus::UNKNOWN;

                    $payment->save();
                }
            });

            VerifyPaymentAttemptJob::dispatch(
                $attempt->id
            )->onQueue('payments')->delay(now()->addSeconds(10));

            throw $e;
        }
    }

    private function mapSuccessStatus(
        string $gatewayStatus
    ): PaymentAttemptStatus {
        return match (strtolower($gatewayStatus)) {
            'created',
            'initiated',
            'authorized',
            'processing',
            'pending'
            => PaymentAttemptStatus::INITIATED,

            'captured',
            'paid',
            'succeeded',
            'success'
            => PaymentAttemptStatus::SUCCEEDED,

            'failed'
            => PaymentAttemptStatus::FAILED,

            default
            => PaymentAttemptStatus::UNKNOWN,
        };
    }
}