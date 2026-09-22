<?php

namespace App\Payments\Services;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentReconciliation;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\ReconciliationMismatchType;
use App\Payments\Enums\ReconciliationResolutionStatus;
use App\Payments\Enums\ReconciliationStatus;
use Illuminate\Support\Facades\DB;
use App\Payments\GatewayResolver;

class PaymentReconciliationService
{
    public function __construct(
        private GatewayResolver $gatewayResolver,
        private PaymentTransactionService $transactionService,
        private PaymentReconciliationRecoveryService $recoveryService
    ) {
    }

    public function reconcile(
        PaymentAttempt $attempt
    ): PaymentReconciliation {
        $attempt->loadMissing([
            'payment',
            'provider',
        ]);

        if (!$attempt->provider_payment_id) {
            return $this->recordMismatch(
                $attempt,
                ReconciliationMismatchType::MISSING_PROVIDER_TRANSACTION,
                'Provider payment ID is missing from local attempt.'
            );
        }

        $gateway = $this->gatewayResolver->resolve(
            $attempt->provider
        );

        /*
         * External API call MUST remain outside our DB transaction.
         */
        $providerResponse = $gateway->getPaymentDetails(
            $attempt
        );

        if (
            !$providerResponse->success &&
            in_array(
                strtolower($providerResponse->status),
                ['failed', 'cancelled'],
                true
            )
        ) {
            return $this->reconcileFailedPayment(
                $attempt,
                $providerResponse
            );
        }

        if (!$providerResponse->success) {
            return $this->recordMismatch(
                $attempt,
                ReconciliationMismatchType::STATUS,
                'Provider returned an unresolved payment status.',
                $providerResponse->status,
                $providerResponse->data
            );
        }

        return $this->reconcileSuccessfulPayment(
            $attempt,
            $providerResponse
        );
    }

    private function reconcileSuccessfulPayment(
        PaymentAttempt $attempt,
        $providerResponse
    ): PaymentReconciliation {
        $payment = $attempt->payment;

        $providerData = $providerResponse->data;

        $providerAmount = (int) (
            $providerData['amount'] ?? 0
        );

        $providerCurrency = strtoupper(
            (string) (
                $providerData['currency'] ?? ''
            )
        );

        /*
         * NEVER mark a payment PAID when amount differs.
         */
        if ($providerAmount !== (int) $payment->amount) {
            return $this->recordMismatch(
                $attempt,
                ReconciliationMismatchType::AMOUNT,
                'Provider amount does not match local payment amount.',
                $providerResponse->status,
                $providerData,
                $providerAmount,
                $providerCurrency
            );
        }

        /*
         * NEVER mark a payment PAID when currency differs.
         */
        if (
            $providerCurrency !==
            strtoupper($payment->currency)
        ) {
            return $this->recordMismatch(
                $attempt,
                ReconciliationMismatchType::CURRENCY,
                'Provider currency does not match local payment currency.',
                $providerResponse->status,
                $providerData,
                $providerAmount,
                $providerCurrency
            );
        }

        $providerTransactionId =
            $providerResponse->providerPaymentId
            ?? $providerData['id']
            ?? null;

        if (!$providerTransactionId) {
            return $this->recordMismatch(
                $attempt,
                ReconciliationMismatchType::UNKNOWN,
                'Provider returned successful status without transaction ID.',
                $providerResponse->status,
                $providerData,
                $providerAmount,
                $providerCurrency
            );
        }

        /*
         * Financial state change is centralized.
         */
        $this->transactionService->recordSuccessfulSale(
            payment: $payment,
            attempt: $attempt,
            providerTransactionId: $providerTransactionId,
            amount: $providerAmount,
            currency: $providerCurrency,
            metadata: [
                'source' => 'reconciliation',
                'provider_status' => $providerResponse->status,
            ]
        );

        return $this->recordMatched(
            $attempt,
            $providerResponse->status,
            $providerData,
            $providerAmount,
            $providerCurrency
        );
    }

    private function reconcileFailedPayment(
        PaymentAttempt $attempt,
        $providerResponse
    ): PaymentReconciliation {
        return DB::transaction(function () use ($attempt, $providerResponse) {
            $attempt = PaymentAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();

            $payment = Payment::query()
                ->whereKey($attempt->payment_id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Never overwrite an already-paid payment with FAILED.
             */
            if ($payment->status === PaymentStatus::PAID) {
                return $this->recordMismatchInsideTransaction(
                    $attempt,
                    ReconciliationMismatchType::STATUS,
                    'Provider reports failure but local payment is already paid.',
                    $providerResponse->status,
                    $providerResponse->data
                );
            }

            $attempt->status = PaymentAttemptStatus::FAILED;
            $attempt->failure_reason =
                $providerResponse->message
                ?? 'Provider reports payment failure.';
            $attempt->completed_at = now();
            $attempt->save();

            $payment->status = PaymentStatus::FAILED;
            $payment->save();

            return $this->createReconciliation(
                attempt: $attempt,
                status: ReconciliationStatus::MATCHED,
                mismatchType: null,
                providerStatus: $providerResponse->status,
                providerData: $providerResponse->data,
                resolutionStatus:
                ReconciliationResolutionStatus::RESOLVED
            );
        });
    }

    private function recordMatched(
        PaymentAttempt $attempt,
        string $providerStatus,
        array $providerData,
        int $providerAmount,
        string $providerCurrency
    ): PaymentReconciliation {
        return $this->createReconciliation(
            attempt: $attempt,
            status: ReconciliationStatus::MATCHED,
            mismatchType: null,
            providerStatus: $providerStatus,
            providerData: $providerData,
            providerAmount: $providerAmount,
            providerCurrency: $providerCurrency,
            resolutionStatus:
            ReconciliationResolutionStatus::IN_PROGRESS
        );
    }

    private function recordMismatch(
        PaymentAttempt $attempt,
        ReconciliationMismatchType $type,
        string $note,
        ?string $providerStatus = null,
        array $providerData = [],
        ?int $providerAmount = null,
        ?string $providerCurrency = null
    ): PaymentReconciliation {
        return $this->createReconciliation(
            attempt: $attempt,
            status: ReconciliationStatus::MISMATCH,
            mismatchType: $type,
            providerStatus: $providerStatus,
            providerData: $providerData,
            providerAmount: $providerAmount,
            providerCurrency: $providerCurrency,
            resolutionStatus:
            ReconciliationResolutionStatus::MANUAL_REVIEW,
            note: $note
        );
    }

    private function recordMismatchInsideTransaction(
        PaymentAttempt $attempt,
        ReconciliationMismatchType $type,
        string $note,
        ?string $providerStatus,
        array $providerData
    ): PaymentReconciliation {
        return $this->createReconciliation(
            attempt: $attempt,
            status: ReconciliationStatus::MISMATCH,
            mismatchType: $type,
            providerStatus: $providerStatus,
            providerData: $providerData,
            resolutionStatus:
            ReconciliationResolutionStatus::MANUAL_REVIEW,
            note: $note
        );
    }

    private function createReconciliation(
        PaymentAttempt $attempt,
        ReconciliationStatus $status,
        ?ReconciliationMismatchType $mismatchType,
        ?string $providerStatus,
        array $providerData,
        ?int $providerAmount = null,
        ?string $providerCurrency = null,
        ReconciliationResolutionStatus $resolutionStatus =
        ReconciliationResolutionStatus::PENDING,
        ?string $note = null
    ): PaymentReconciliation {
        $payment = $attempt->payment;
        $providerTransactionId =
            $providerData['id'] ?? null;

        if ($providerTransactionId !== null) {
            $existing = PaymentReconciliation::query()
                ->where('payment_attempt_id', $attempt->id)
                ->where(
                    'provider_transaction_id',
                    $providerTransactionId
                )
                ->first();

            if ($existing) {
                return $existing;
            }
        }
        return PaymentReconciliation::create([
            'payment_provider_id' => $attempt->payment_provider_id,
            'payment_id' => $attempt->payment_id,
            'payment_attempt_id' => $attempt->id,

            'reconciliation_uuid' => (string) \Illuminate\Support\Str::uuid(),

            'provider_transaction_id' => $providerTransactionId,

            'provider_payment_id' =>
                $providerData['id'] ?? null,

            'provider_order_id' =>
                $providerData['order_id'] ?? null,

            'local_status' =>
                $payment->status instanceof \BackedEnum
                ? $payment->status->value
                : $payment->status,

            'provider_status' => $providerStatus,

            'local_amount' => $payment->amount,

            'provider_amount' => $providerAmount,

            'local_currency' => strtoupper(
                $payment->currency
            ),

            'provider_currency' => $providerCurrency,

            'status' => $status,

            'mismatch_type' => $mismatchType,

            'resolution_status' => $resolutionStatus,

            'local_data' => [
                'payment_uuid' => $payment->uuid,
                'merchant_reference' =>
                    $payment->merchant_reference,
                'attempt_uuid' =>
                    $attempt->attempt_uuid,
                'attempt_status' =>
                    $attempt->status instanceof \BackedEnum
                    ? $attempt->status->value
                    : $attempt->status,
            ],

            'provider_data' => $providerData,

            'resolution_note' => $note,

            'detected_at' => now(),

            'resolved_at' =>
                $resolutionStatus ===
                ReconciliationResolutionStatus::RESOLVED
                ? now()
                : null,
        ]);
    }
}