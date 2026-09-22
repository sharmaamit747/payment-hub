<?php

namespace App\Payments\Services;

use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Models\PaymentWebhook;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\RefundStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentRefundWebhookService
{
    public function process(
        PaymentWebhook $webhook,
        array $refundEntity
    ): PaymentRefund {
        return DB::transaction(function () use (
            $webhook,
            $refundEntity
        ) {
            $webhook = PaymentWebhook::query()
                ->whereKey($webhook->id)
                ->lockForUpdate()
                ->firstOrFail();

            $provider = $webhook->provider;

            $providerRefundId = $refundEntity['id'] ?? null;
            $providerPaymentId = $refundEntity['payment_id'] ?? null;

            if (!$providerRefundId) {
                throw new RuntimeException(
                    'Refund webhook does not contain provider refund ID.'
                );
            }

            /*
             * First try exact provider refund ID.
             */
            $refund = PaymentRefund::query()
                ->where('payment_provider_id', $provider->id)
                ->where('provider_refund_id', $providerRefundId)
                ->lockForUpdate()
                ->first();

            /*
             * If provider refund ID is not yet stored locally,
             * match through the provider payment ID.
             */
            if (!$refund && $providerPaymentId) {
                $attempt = PaymentAttempt::query()
                    ->where('payment_provider_id', $provider->id)
                    ->where('provider_payment_id', $providerPaymentId)
                    ->latest('id')
                    ->first();

                if ($attempt) {
                    $refund = PaymentRefund::query()
                        ->where('payment_attempt_id', $attempt->id)
                        ->whereNull('provider_refund_id')
                        ->whereIn('status', [
                            RefundStatus::PENDING->value,
                            RefundStatus::PROCESSING->value,
                            RefundStatus::UNKNOWN->value,
                        ])
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();
                }
            }

            if (!$refund) {
                throw new RuntimeException(
                    "Unable to match provider refund [{$providerRefundId}] to local refund."
                );
            }

            $providerAmount = (int) ($refundEntity['amount'] ?? 0);

            if ($providerAmount !== (int) $refund->amount) {
                throw new RuntimeException(
                    'Refund webhook amount does not match local refund.'
                );
            }

            $providerCurrency = strtoupper(
                (string) ($refundEntity['currency'] ?? '')
            );

            if ($providerCurrency !== strtoupper($refund->currency)) {
                throw new RuntimeException(
                    'Refund webhook currency does not match local refund.'
                );
            }

            $refund->provider_refund_id = $providerRefundId;

            if ($webhook->event_type === 'refund.processed') {
                $refund->status = RefundStatus::SUCCEEDED;
                $refund->processed_at = now();
            }

            if ($webhook->event_type === 'refund.failed') {
                $refund->status = RefundStatus::FAILED;

                $refund->failure_reason =
                    data_get($refundEntity, 'error.description')
                    ?? data_get($refundEntity, 'notes.reason')
                    ?? 'Refund failed at provider.';
            }

            $refund->metadata = array_merge(
                $refund->metadata ?? [],
                [
                    'webhook_event' => $webhook->event_type,
                    'webhook_event_id' => $webhook->provider_event_id,
                    'provider_response' => $refundEntity,
                ]
            );

            $refund->save();

            $this->syncPaymentState($refund);

            return $refund->fresh();
        });
    }

    private function syncPaymentState(
        PaymentRefund $refund
    ): void {
        $payment = $refund->payment()
            ->lockForUpdate()
            ->firstOrFail();

        $successfulAmount = (int) $payment->refunds()
            ->where(
                'status',
                RefundStatus::SUCCEEDED->value
            )
            ->sum('amount');

        $pendingAmount = (int) $payment->refunds()
            ->whereIn('status', [
                RefundStatus::PENDING->value,
                RefundStatus::PROCESSING->value,
                RefundStatus::UNKNOWN->value,
            ])
            ->sum('amount');

        $payment->amount_refunded = $successfulAmount;

        if ($successfulAmount >= $payment->amount) {
            $payment->status = PaymentStatus::REFUNDED;
        } elseif ($pendingAmount > 0) {
            $payment->status = PaymentStatus::REFUND_PENDING;
        } elseif ($successfulAmount > 0) {
            $payment->status = PaymentStatus::PARTIALLY_REFUNDED;
        } else {
            $payment->status = PaymentStatus::PAID;
        }

        $payment->save();
    }
}