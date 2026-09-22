<?php

namespace App\Payments\Services;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentReconciliation;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\ReconciliationResolutionStatus;
use App\Payments\Enums\ReconciliationStatus;
use App\Payments\Enums\TransactionStatus;
use App\Payments\Enums\TransactionType;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PaymentReconciliationRecoveryService
{
    public function __construct(
        private PaymentTransactionService $transactionService,
        private PaymentStateService $paymentStateService,
        private PaymentAttemptStateService $attemptStateService,
        private PaymentFinancialInvariantService $invariantService,
    ) {
    }

    /**
     * Recover a payment that the provider confirms as successful.
     *
     * This method must be idempotent.
     *
     * It is safe to call multiple times for the same reconciliation record.
     */
    public function recoverSuccessfulPayment(
        PaymentReconciliation $reconciliation
    ): PaymentReconciliation {
        return DB::transaction(function () use ($reconciliation) {

            /*
             * Lock reconciliation first.
             */
            $reconciliation = PaymentReconciliation::query()
                ->whereKey($reconciliation->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Already resolved successfully.
             */
            if (
                $reconciliation->resolution_status
                === ReconciliationResolutionStatus::RESOLVED
            ) {
                return $reconciliation->fresh();
            }

            /*
             * A reconciliation marked as ignored/manual review must
             * not be automatically changed by recovery.
             */
            if (
                in_array(
                    $reconciliation->resolution_status,
                    [
                        ReconciliationResolutionStatus::MANUAL_REVIEW,
                        ReconciliationResolutionStatus::IGNORED,
                    ],
                    true
                )
            ) {
                return $reconciliation->fresh();
            }

            /*
             * Lock payment.
             */
            $payment = Payment::query()
                ->whereKey($reconciliation->payment_id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Lock attempt when available.
             */
            $attempt = null;

            if ($reconciliation->payment_attempt_id) {
                $attempt = PaymentAttempt::query()
                    ->whereKey($reconciliation->payment_attempt_id)
                    ->lockForUpdate()
                    ->first();
            }

            /*
             * Fallback: locate the latest attempt belonging to the
             * payment/provider.
             */
            if (!$attempt) {
                $attemptQuery = PaymentAttempt::query()
                    ->where('payment_id', $payment->id)
                    ->where(
                        'payment_provider_id',
                        $reconciliation->payment_provider_id
                    );

                if ($reconciliation->provider_payment_id) {
                    $attemptQuery->where(
                        'provider_payment_id',
                        $reconciliation->provider_payment_id
                    );
                }

                $attempt = $attemptQuery
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();
            }

            if (!$attempt) {
                return $this->markManualReview(
                    $reconciliation,
                    'Unable to locate a matching local payment attempt.'
                );
            }

            /*
             * Validate provider/local financial data before changing
             * the local payment state.
             */
            $this->validateProviderData(
                reconciliation: $reconciliation,
                payment: $payment,
                attempt: $attempt
            );

            /*
             * Global financial invariants.
             */
            $this->invariantService->assertPayment($payment);

            /*
             * If already fully paid, there is nothing to recover.
             *
             * We still mark reconciliation resolved because the local
             * financial state already agrees with the provider.
             */
            if (
                $payment->status === PaymentStatus::PAID
                && (int) $payment->amount_paid === (int) $payment->amount
            ) {
                $this->markResolved(
                    reconciliation: $reconciliation,
                    note: 'Payment was already marked as paid locally.'
                );

                return $reconciliation->fresh();
            }

            /*
             * Do not automatically resurrect terminal financial states.
             */
            if (
                in_array(
                    $payment->status,
                    [
                        PaymentStatus::REFUNDED,
                        PaymentStatus::CANCELLED,
                        PaymentStatus::EXPIRED,
                    ],
                    true
                )
            ) {
                return $this->markManualReview(
                    $reconciliation,
                    "Provider reports successful payment but local payment is in terminal state [{$payment->status->value}]."
                );
            }

            /*
             * A payment with refund activity needs additional care.
             * We do not silently convert a refund state back to PAID.
             */
            if (
                in_array(
                    $payment->status,
                    [
                        PaymentStatus::REFUND_PENDING,
                        PaymentStatus::PARTIALLY_REFUNDED,
                    ],
                    true
                )
            ) {
                return $this->markManualReview(
                    $reconciliation,
                    "Provider reports successful payment while local payment is in refund state [{$payment->status->value}]."
                );
            }

            /*
             * Validate the provider amount against the original payment.
             */
            $providerAmount = (int) $reconciliation->provider_amount;

            if ($providerAmount !== (int) $payment->amount) {
                return $this->markManualReview(
                    $reconciliation,
                    "Provider amount [{$providerAmount}] does not match local payment amount [{$payment->amount}]."
                );
            }

            /*
             * Validate currency.
             */
            $providerCurrency = strtoupper(
                trim((string) $reconciliation->provider_currency)
            );

            $localCurrency = strtoupper(
                trim((string) $payment->currency)
            );

            if (
                $providerCurrency !== ''
                && $providerCurrency !== $localCurrency
            ) {
                return $this->markManualReview(
                    $reconciliation,
                    "Provider currency [{$providerCurrency}] does not match local currency [{$localCurrency}]."
                );
            }

            /*
             * If the attempt is already succeeded but payment is not paid,
             * we still recover the payment transaction below.
             */
            if (
                $attempt->status !== PaymentAttemptStatus::SUCCEEDED
            ) {
                if (
                    in_array(
                        $attempt->status,
                        [
                            PaymentAttemptStatus::CANCELLED,
                            PaymentAttemptStatus::EXPIRED,
                        ],
                        true
                    )
                ) {
                    return $this->markManualReview(
                        $reconciliation,
                        "Provider reports successful payment but local attempt is terminal [{$attempt->status->value}]."
                    );
                }

                /*
                 * UNKNOWN/PENDING/FAILED attempts can be recovered because
                 * reconciliation has authoritative provider evidence.
                 */
                $this->attemptStateService->transition(
                    $attempt,
                    PaymentAttemptStatus::SUCCEEDED
                );
            }

            /*
             * Provider transaction/payment ID.
             */
            $providerTransactionId =
                $reconciliation->provider_transaction_id
                ?: $reconciliation->provider_payment_id;

            if (!$providerTransactionId) {
                return $this->markManualReview(
                    $reconciliation,
                    'Provider transaction/payment ID is missing.'
                );
            }

            /*
             * Record the successful SALE transaction.
             *
             * PaymentTransactionService must itself be idempotent against
             * provider transaction ID/idempotency key.
             */
            $transaction = $this->transactionService->recordSuccessfulSale(
                payment: $payment,
                attempt: $attempt,
                providerTransactionId: $providerTransactionId,
                amount: $providerAmount,
                currency: $providerCurrency ?: $localCurrency,
                metadata: [
                    'source' => 'reconciliation_recovery',
                    'reconciliation_id' => $reconciliation->id,
                    'reconciliation_uuid' => $reconciliation->reconciliation_uuid,
                    'provider_payment_id' => $reconciliation->provider_payment_id,
                    'provider_order_id' => $reconciliation->provider_order_id,
                    'provider_transaction_id' => $reconciliation->provider_transaction_id,
                ]
            );

            /*
             * Refresh payment because transaction service may have updated
             * amount_paid/status.
             */
            $payment->refresh();

            /*
             * Final financial invariant check.
             */
            $this->invariantService->assertPayment($payment);

            /*
             * Make absolutely sure a successful reconciliation results in
             * the correct paid amount.
             */
            if (
                (int) $payment->amount_paid !== (int) $payment->amount
            ) {
                /*
                 * Do not force a PAID state when the accounting transaction
                 * does not represent the complete payment.
                 */
                return $this->markManualReview(
                    $reconciliation,
                    "Recovery transaction completed but amount_paid [{$payment->amount_paid}] does not equal payment amount [{$payment->amount}]."
                );
            }

            /*
             * Ensure payment is PAID.
             *
             * Transaction service normally does this, but reconciliation
             * recovery should enforce the invariant explicitly.
             */
            if ($payment->status !== PaymentStatus::PAID) {
                $this->paymentStateService->transition(
                    $payment,
                    PaymentStatus::PAID
                );
            }

            /*
             * Resolve reconciliation.
             */
            $this->markResolved(
                reconciliation: $reconciliation,
                note: 'Payment recovered successfully from provider reconciliation.'
            );

            /*
             * Store useful local transaction information.
             */
            $reconciliation->local_data = array_merge(
                $reconciliation->local_data ?? [],
                [
                    'recovered' => true,
                    'recovery_source' => 'provider_reconciliation',
                    'transaction_id' => $transaction->id,
                    'transaction_uuid' => $transaction->transaction_uuid,
                    'recovered_at' => now()->toIso8601String(),
                ]
            );

            $reconciliation->save();

            return $reconciliation->fresh();
        });
    }

    /**
     * Recover a payment reported as failed by the provider.
     *
     * A locally paid payment is never automatically downgraded to FAILED.
     */
    public function recoverFailedPayment(
        PaymentReconciliation $reconciliation
    ): PaymentReconciliation {
        return DB::transaction(function () use ($reconciliation) {

            $reconciliation = PaymentReconciliation::query()
                ->whereKey($reconciliation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $reconciliation->resolution_status
                === ReconciliationResolutionStatus::RESOLVED
            ) {
                return $reconciliation->fresh();
            }

            if (
                in_array(
                    $reconciliation->resolution_status,
                    [
                        ReconciliationResolutionStatus::MANUAL_REVIEW,
                        ReconciliationResolutionStatus::IGNORED,
                    ],
                    true
                )
            ) {
                return $reconciliation->fresh();
            }

            $payment = Payment::query()
                ->whereKey($reconciliation->payment_id)
                ->lockForUpdate()
                ->firstOrFail();

            $attempt = null;

            if ($reconciliation->payment_attempt_id) {
                $attempt = PaymentAttempt::query()
                    ->whereKey($reconciliation->payment_attempt_id)
                    ->lockForUpdate()
                    ->first();
            }

            /*
             * Never turn a successful local payment into FAILED merely
             * because one provider reconciliation says failed.
             */
            if ($payment->status === PaymentStatus::PAID) {
                return $this->markManualReview(
                    $reconciliation,
                    'Provider reports failed payment but local payment is already PAID.'
                );
            }

            /*
             * Refunded payments cannot be changed to FAILED.
             */
            if (
                in_array(
                    $payment->status,
                    [
                        PaymentStatus::REFUNDED,
                        PaymentStatus::PARTIALLY_REFUNDED,
                        PaymentStatus::REFUND_PENDING,
                    ],
                    true
                )
            ) {
                return $this->markManualReview(
                    $reconciliation,
                    "Provider reports failed payment while local payment is in [{$payment->status->value}] state."
                );
            }

            /*
             * Attempt can safely be marked FAILED if it is not terminal.
             */
            if ($attempt) {
                if (
                    !in_array(
                        $attempt->status,
                        [
                            PaymentAttemptStatus::SUCCEEDED,
                            PaymentAttemptStatus::CANCELLED,
                            PaymentAttemptStatus::EXPIRED,
                        ],
                        true
                    )
                ) {
                    $this->attemptStateService->transition(
                        $attempt,
                        PaymentAttemptStatus::FAILED
                    );
                }

                /*
                 * If an attempt was already succeeded, provider failure
                 * creates an inconsistency and therefore needs review.
                 */
                if (
                    $attempt->status === PaymentAttemptStatus::SUCCEEDED
                ) {
                    return $this->markManualReview(
                        $reconciliation,
                        'Provider reports failed payment but local attempt is already SUCCEEDED.'
                    );
                }
            }

            /*
             * Payment can be moved to FAILED unless it is already terminal.
             */
            if (
                !in_array(
                    $payment->status,
                    [
                        PaymentStatus::FAILED,
                        PaymentStatus::CANCELLED,
                        PaymentStatus::EXPIRED,
                    ],
                    true
                )
            ) {
                $this->paymentStateService->transition(
                    $payment,
                    PaymentStatus::FAILED
                );
            }

            $reconciliation->local_data = array_merge(
                $reconciliation->local_data ?? [],
                [
                    'recovered' => true,
                    'recovery_source' => 'provider_reconciliation',
                    'recovered_at' => now()->toIso8601String(),
                ]
            );

            $this->markResolved(
                reconciliation: $reconciliation,
                note: 'Payment failure confirmed by provider reconciliation.'
            );

            $reconciliation->save();

            return $reconciliation->fresh();
        });
    }

    /**
     * Mark reconciliation as matched without modifying financial state.
     */
    public function markMatched(
        PaymentReconciliation $reconciliation,
        ?string $note = null
    ): PaymentReconciliation {
        return DB::transaction(function () use ($reconciliation, $note) {

            $reconciliation = PaymentReconciliation::query()
                ->whereKey($reconciliation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $reconciliation->status = ReconciliationStatus::MATCHED;
            $reconciliation->resolution_status =
                ReconciliationResolutionStatus::RESOLVED;

            if ($note !== null) {
                $reconciliation->resolution_note = $note;
            }

            $reconciliation->resolved_at = now();

            $reconciliation->save();

            return $reconciliation->fresh();
        });
    }

    /**
     * Mark reconciliation as manual review.
     *
     * Automatic recovery must stop here.
     */
    public function markManualReview(
        PaymentReconciliation $reconciliation,
        string $reason
    ): PaymentReconciliation {
        $reconciliation->status = ReconciliationStatus::MISMATCH;
        $reconciliation->resolution_status =
            ReconciliationResolutionStatus::MANUAL_REVIEW;
        $reconciliation->resolution_note =
            mb_substr($reason, 0, 1000);

        $reconciliation->resolved_at = null;

        $reconciliation->save();

        return $reconciliation->fresh();
    }

    /**
     * Validate provider reconciliation data against local payment data.
     */
    private function validateProviderData(
        PaymentReconciliation $reconciliation,
        Payment $payment,
        PaymentAttempt $attempt
    ): void {
        /*
         * Provider must identify the payment.
         */
        if (
            !$reconciliation->provider_payment_id
            && !$reconciliation->provider_transaction_id
        ) {
            throw new RuntimeException(
                'Reconciliation does not contain a provider payment or transaction ID.'
            );
        }

        /*
         * If reconciliation contains a provider payment ID and the local
         * attempt already has one, they must match.
         */
        if (
            $reconciliation->provider_payment_id
            && $attempt->provider_payment_id
            && $reconciliation->provider_payment_id
            !== $attempt->provider_payment_id
        ) {
            throw new RuntimeException(
                'Provider payment ID does not match the local payment attempt.'
            );
        }

        /*
         * Provider order ID, when available, must also agree.
         */
        if (
            $reconciliation->provider_order_id
            && $attempt->provider_order_id
            && $reconciliation->provider_order_id
            !== $attempt->provider_order_id
        ) {
            throw new RuntimeException(
                'Provider order ID does not match the local payment attempt.'
            );
        }

        /*
         * Amount is mandatory for successful recovery.
         */
        if (
            $reconciliation->provider_amount === null
            || (int) $reconciliation->provider_amount <= 0
        ) {
            throw new RuntimeException(
                'Provider reconciliation amount is missing or invalid.'
            );
        }

        /*
         * Currency is mandatory for successful recovery.
         */
        if (
            !$reconciliation->provider_currency
        ) {
            throw new RuntimeException(
                'Provider reconciliation currency is missing.'
            );
        }

        /*
         * Final local payment validation.
         */
        $this->invariantService->assertPayment($payment);
    }

    /**
     * Mark reconciliation resolved.
     */
    private function markResolved(
        PaymentReconciliation $reconciliation,
        string $note
    ): void {
        $reconciliation->status = ReconciliationStatus::MATCHED;

        $reconciliation->resolution_status =
            ReconciliationResolutionStatus::RESOLVED;

        $reconciliation->resolution_note =
            mb_substr($note, 0, 1000);

        $reconciliation->resolved_at = now();

        $reconciliation->save();
    }
}