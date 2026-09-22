<?php

namespace App\Payments\Services;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\TransactionStatus;
use App\Payments\Enums\TransactionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentTransactionService
{
    public function __construct(
        private PaymentStateService $paymentStateService,
        private PaymentAttemptStateService $attemptStateService,
        private PaymentFinancialInvariantService $invariantService
    ) {
    }

    /**
     * Record a successful SALE transaction.
     *
     * This operation is idempotent using:
     *
     * provider_id + provider_transaction_id
     *
     * The method is safe to call repeatedly for the same provider
     * transaction without creating duplicate financial effects.
     */
    public function recordSuccessfulSale(
        Payment $payment,
        PaymentAttempt $attempt,
        string $providerTransactionId,
        int $amount,
        string $currency,
        array $metadata = []
    ): PaymentTransaction {
        $providerTransactionId = trim($providerTransactionId);
        $currency = strtoupper(trim($currency));

        if ($providerTransactionId === '') {
            throw new RuntimeException(
                'Provider transaction ID is required.'
            );
        }

        if ($amount <= 0) {
            throw new RuntimeException(
                'Transaction amount must be greater than zero.'
            );
        }

        if ($currency === '') {
            throw new RuntimeException(
                'Transaction currency is required.'
            );
        }

        return DB::transaction(function () use (
            $payment,
            $attempt,
            $providerTransactionId,
            $amount,
            $currency,
            $metadata
        ) {
            /*
             * ---------------------------------------------------------
             * 1. Lock payment
             * ---------------------------------------------------------
             *
             * This serializes concurrent financial updates for the
             * same payment.
             */
            $payment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * ---------------------------------------------------------
             * 2. Lock attempt
             * ---------------------------------------------------------
             */
            $attempt = PaymentAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Ensure the attempt actually belongs to the payment.
             */
            if ((int) $attempt->payment_id !== (int) $payment->id) {
                throw new RuntimeException(
                    'Payment attempt does not belong to the payment.'
                );
            }

            /*
             * ---------------------------------------------------------
             * 3. Validate financial data
             * ---------------------------------------------------------
             */
            if ($amount !== (int) $payment->amount) {
                throw new RuntimeException(
                    "Payment amount mismatch. Expected [{$payment->amount}], received [{$amount}]."
                );
            }

            if ($currency !== strtoupper((string) $payment->currency)) {
                throw new RuntimeException(
                    "Payment currency mismatch. Expected [{$payment->currency}], received [{$currency}]."
                );
            }

            /*
             * Validate the existing local payment before modifying it.
             */
            $this->invariantService->assertPayment($payment);

            /*
             * ---------------------------------------------------------
             * 4. Find existing provider transaction
             * ---------------------------------------------------------
             *
             * This is the primary duplicate-protection mechanism.
             *
             * The database also has a unique constraint on:
             *
             * provider_id + provider_transaction_id
             */
            $existing = PaymentTransaction::query()
                ->where(
                    'payment_provider_id',
                    $attempt->payment_provider_id
                )
                ->where(
                    'provider_transaction_id',
                    $providerTransactionId
                )
                ->lockForUpdate()
                ->first();

            if ($existing) {
                /*
                 * A provider transaction must never move between
                 * payments.
                 */
                if ((int) $existing->payment_id !== (int) $payment->id) {
                    throw new RuntimeException(
                        'Provider transaction is already linked to another payment.'
                    );
                }

                /*
                 * Also ensure the transaction amount/currency are
                 * consistent with this payment.
                 */
                if ((int) $existing->amount !== $amount) {
                    throw new RuntimeException(
                        'Existing provider transaction amount does not match the payment.'
                    );
                }

                if (
                    strtoupper((string) $existing->currency)
                    !== $currency
                ) {
                    throw new RuntimeException(
                        'Existing provider transaction currency does not match the payment.'
                    );
                }

                /*
                 * The financial transaction already exists.
                 *
                 * Do NOT increment amount_paid again.
                 */
                return $existing->fresh();
            }

            /*
             * ---------------------------------------------------------
             * 5. Protect already-paid payments
             * ---------------------------------------------------------
             *
             * If the payment is already fully paid but the provider
             * transaction is not recorded locally, do NOT blindly
             * create another financial transaction.
             *
             * This should normally be resolved by reconciliation.
             */
            if (
                $payment->status === PaymentStatus::PAID
                && (int) $payment->amount_paid === (int) $payment->amount
            ) {
                throw new RuntimeException(
                    'Payment is already PAID but this provider transaction is not recorded locally. Reconciliation is required.'
                );
            }

            /*
             * ---------------------------------------------------------
             * 6. Create financial transaction
             * ---------------------------------------------------------
             */
            $transaction = PaymentTransaction::create([
                'payment_id' => $payment->id,

                'payment_attempt_id' => $attempt->id,

                'payment_provider_id' =>
                    $attempt->payment_provider_id,

                'transaction_uuid' =>
                    (string) Str::uuid(),

                'provider_transaction_id' =>
                    $providerTransactionId,

                'idempotency_key' =>
                    'sale:' . $providerTransactionId,

                'type' =>
                    TransactionType::SALE,

                'status' =>
                    TransactionStatus::SUCCEEDED,

                'amount' =>
                    $amount,

                'currency' =>
                    $currency,

                'metadata' =>
                    $metadata,

                'processed_at' =>
                    now(),
            ]);

            /*
             * ---------------------------------------------------------
             * 7. Update attempt
             * ---------------------------------------------------------
             *
             * Update provider identifiers BEFORE state transition.
             */
            if (!$attempt->provider_payment_id) {
                $attempt->provider_payment_id =
                    $providerTransactionId;
            }

            $attempt->completed_at = now();

            $this->attemptStateService->transition(
                $attempt,
                PaymentAttemptStatus::SUCCEEDED
            );

            /*
             * ---------------------------------------------------------
             * 8. Update payment financial values
             * ---------------------------------------------------------
             *
             * IMPORTANT:
             *
             * amount_paid must be updated BEFORE calling
             * PaymentStateService::transition().
             *
             * Otherwise the PAID invariant fails.
             */
            $payment->amount_paid = $amount;
            $payment->paid_at = now();

            /*
             * Validate the financial values before changing state.
             */
            $this->invariantService->assertPayment($payment);

            /*
             * ---------------------------------------------------------
             * 9. Transition payment → PAID
             * ---------------------------------------------------------
             */
            $this->paymentStateService->transition(
                $payment,
                PaymentStatus::PAID
            );

            /*
             * ---------------------------------------------------------
             * 10. Final invariant validation
             * ---------------------------------------------------------
             */
            $this->invariantService->assertPayment($payment);

            /*
             * ---------------------------------------------------------
             * 11. Return transaction
             * ---------------------------------------------------------
             */
            return $transaction->fresh();
        });
    }
}
