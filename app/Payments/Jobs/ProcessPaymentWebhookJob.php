<?php

namespace App\Payments\Jobs;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhook;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\WebhookStatus;
use App\Payments\Services\PaymentRefundWebhookService;
use App\Payments\Services\PaymentTransactionService;
use App\Payments\Services\PaymentAttemptStateService;
use App\Payments\Services\PaymentStateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProcessPaymentWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 10;

    public function __construct(
        public int $webhookId
    ) {
        $this->onQueue('payment-webhooks');
    }

    public function handle(): void
    {
        try {
            DB::transaction(function () {

                $webhook = PaymentWebhook::query()
                    ->whereKey($this->webhookId)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * Idempotency:
                 * Already processed webhooks must not be processed again.
                 */
                /*
                 * Already completed.
                 */
                if ($webhook->status === WebhookStatus::PROCESSED) {
                    return;
                }

                /*
                 * Already intentionally ignored.
                 */
                if ($webhook->status === WebhookStatus::IGNORED) {
                    return;
                }

                /*
                 * Recover a webhook that was left in PROCESSING because
                 * the worker crashed.
                 */
                if (
                    $webhook->status === WebhookStatus::PROCESSING
                    && $webhook->updated_at
                    && $webhook->updated_at->lt(now()->subMinutes(10))
                ) {
                    $webhook->status = WebhookStatus::RECEIVED;
                    $webhook->save();
                }
                if ($webhook->status === WebhookStatus::PROCESSED) {
                    return;
                }

                $webhook->update([
                    'status' => WebhookStatus::PROCESSING,
                    'attempts' => $webhook->attempts + 1,
                ]);

                $payload = $webhook->payload;

                /*
                 * ---------------------------------------------------------
                 * REFUND WEBHOOKS
                 * ---------------------------------------------------------
                 *
                 * Refund webhooks have the refund entity.
                 * Do NOT require a payment entity here.
                 */
                if (
                    in_array($webhook->event_type, [
                        'refund.processed',
                        'refund.failed',
                    ], true)
                ) {

                    $refundEntity = data_get(
                        $payload,
                        'payload.refund.entity',
                        []
                    );

                    if (!$refundEntity) {
                        throw new RuntimeException(
                            'Razorpay refund entity not found in webhook.'
                        );
                    }

                    $this->processRefundWebhook(
                        $webhook,
                        $refundEntity
                    );

                    $webhook->update([
                        'status' => WebhookStatus::PROCESSED,
                        'processed_at' => now(),
                        'failed_at' => null,
                        'failure_reason' => null,
                    ]);

                    return;
                }

                /*
                 * ---------------------------------------------------------
                 * PAYMENT WEBHOOKS
                 * ---------------------------------------------------------
                 */
                $paymentEntity =
                    $payload['payload']['payment']['entity']
                    ?? null;

                if (!$paymentEntity) {
                    throw new RuntimeException(
                        'Razorpay payment entity not found in webhook.'
                    );
                }

                $providerPaymentId =
                    $paymentEntity['id'] ?? null;

                if (!$providerPaymentId) {
                    throw new RuntimeException(
                        'Razorpay provider payment ID is missing.'
                    );
                }

                /*
                 * Find local attempt.
                 */
                $attempt = PaymentAttempt::query()
                    ->where(
                        'payment_provider_id',
                        $webhook->payment_provider_id
                    )
                    ->where(function ($query) use ($providerPaymentId, $paymentEntity) {
                        $query->where(
                            'provider_payment_id',
                            $providerPaymentId
                        );

                        if (!empty($paymentEntity['order_id'])) {
                            $query->orWhere(
                                'provider_order_id',
                                $paymentEntity['order_id']
                            );
                        }
                    })
                    ->lockForUpdate()
                    ->first();

                if (!$attempt) {
                    throw new RuntimeException(
                        "No payment attempt found for Razorpay payment [{$providerPaymentId}]."
                    );
                }

                /*
                 * Lock payment.
                 */
                $payment = Payment::query()
                    ->whereKey($attempt->payment_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * Link webhook to payment.
                 */
                if ($webhook->payment_id === null) {
                    $webhook->payment_id = $payment->id;
                    $webhook->save();
                }

                /*
                 * Store provider payment ID if missing.
                 */
                if (!$attempt->provider_payment_id) {
                    $attempt->provider_payment_id =
                        $providerPaymentId;

                    $attempt->save();
                }

                /*
                 * Validate amount.
                 */
                $providerAmount =
                    (int) ($paymentEntity['amount'] ?? 0);

                if ($providerAmount !== (int) $payment->amount) {
                    throw new RuntimeException(
                        "Payment amount mismatch. Expected [{$payment->amount}], received [{$providerAmount}]."
                    );
                }

                /*
                 * ---------------------------------------------------------
                 * PAYMENT CAPTURED
                 * ---------------------------------------------------------
                 */
                if ($webhook->event_type === 'payment.captured') {

                    $this->processCapturedPayment(
                        $payment,
                        $attempt,
                        $paymentEntity
                    );
                }

                /*
                 * ---------------------------------------------------------
                 * PAYMENT FAILED
                 * ---------------------------------------------------------
                 */ elseif ($webhook->event_type === 'payment.failed') {

                    $this->processFailedPayment(
                        $payment,
                        $attempt,
                        $paymentEntity
                    );
                }

                /*
                 * ---------------------------------------------------------
                 * UNKNOWN / UNSUPPORTED EVENT
                 * ---------------------------------------------------------
                 */ else {
                    $webhook->update([
                        'status' => WebhookStatus::IGNORED,
                        'processed_at' => now(),
                    ]);

                    return;
                }

                /*
                 * Mark successful processing.
                 */
                $webhook->update([
                    'status' => WebhookStatus::PROCESSED,
                    'processed_at' => now(),
                    'failed_at' => null,
                    'failure_reason' => null,
                ]);
            });

        } catch (Throwable $e) {

            $webhook = PaymentWebhook::find($this->webhookId);

            if ($webhook) {
                $webhook->update([
                    'status' => WebhookStatus::FAILED,
                    'failed_at' => now(),
                    'failure_reason' =>
                        mb_substr($e->getMessage(), 0, 500),
                ]);
            }

            throw $e;
        }
    }

    private function processCapturedPayment(
        Payment $payment,
        PaymentAttempt $attempt,
        array $paymentEntity
    ): void {
        $providerTransactionId = $paymentEntity['id'] ?? null;

        if (!$providerTransactionId) {
            throw new RuntimeException(
                'Provider transaction ID is missing.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Move PaymentAttempt to PROCESSING
        |--------------------------------------------------------------------------
        */

        $attemptStateService = app(PaymentAttemptStateService::class);

        if ($attempt->status !== PaymentAttemptStatus::SUCCEEDED) {

            if ($attempt->status !== PaymentAttemptStatus::PROCESSING) {
                $attempt = $attemptStateService->transition(
                    $attempt,
                    PaymentAttemptStatus::PROCESSING
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Move Payment to PROCESSING
        |--------------------------------------------------------------------------
        */

        $paymentStateService = app(PaymentStateService::class);

        if ($payment->status !== PaymentStatus::PAID) {

            if ($payment->status !== PaymentStatus::PROCESSING) {
                $payment = $paymentStateService->transition(
                    $payment,
                    PaymentStatus::PROCESSING
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Record successful sale
        |--------------------------------------------------------------------------
        */

        $service = app(PaymentTransactionService::class);

        $service->recordSuccessfulSale(
            payment: $payment,
            attempt: $attempt,
            providerTransactionId: $providerTransactionId,
            amount: (int) ($paymentEntity['amount'] ?? 0),
            currency: $paymentEntity['currency'] ?? '',
            metadata: [
                'source' => 'webhook',
                'event_type' => 'payment.captured',
                'provider_payment_id' => $providerTransactionId,
            ]
        );
    }

    private function processFailedPayment(
        Payment $payment,
        PaymentAttempt $attempt,
        array $paymentEntity
    ): void {
        /*
         * Never downgrade a paid payment.
         */
        if ($payment->status === PaymentStatus::PAID) {
            return;
        }

        $attempt->status =
            PaymentAttemptStatus::FAILED;

        $attempt->failure_code =
            $paymentEntity['error_code'] ?? null;

        $attempt->failure_reason =
            $paymentEntity['error_description'] ?? null;

        $attempt->completed_at = now();

        $attempt->save();

        $payment->status =
            PaymentStatus::FAILED;

        $payment->save();
    }

    private function processRefundWebhook(
        PaymentWebhook $webhook,
        array $refundEntity
    ): void {
        app(PaymentRefundWebhookService::class)
            ->process(
                webhook: $webhook,
                refundEntity: $refundEntity
            );
    }
}