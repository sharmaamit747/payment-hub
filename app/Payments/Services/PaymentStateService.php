<?php

namespace App\Payments\Services;

use App\Models\Payment;
use App\Payments\Enums\PaymentStatus;
use RuntimeException;

class PaymentStateService
{
    public function transition(
        Payment $payment,
        PaymentStatus $to
    ): Payment {
        /*
         * IMPORTANT:
         *
         * Do NOT call refresh() here before changing the state.
         *
         * Callers may have already changed financial fields such as:
         *
         * amount_paid
         * amount_refunded
         * paid_at
         *
         * Refreshing here would discard those unsaved changes.
         *
         * Concurrency-sensitive callers are responsible for locking
         * the payment with lockForUpdate().
         */

        $from = $payment->status;

        /*
         * Same state = no-op.
         */
        if ($from === $to) {
            return $payment;
        }

        /*
         * Validate transition.
         */
        $allowed = $this->allowedTransitions($from);

        if (!in_array($to, $allowed, true)) {
            throw new RuntimeException(
                "Invalid payment state transition [{$from->value}] -> [{$to->value}]."
            );
        }

        /*
         * Set the new state.
         */
        $payment->status = $to;

        /*
         * Validate the complete financial state BEFORE saving.
         */
        $this->assertInvariants($payment);

        /*
         * Persist state + financial changes together.
         */
        $payment->save();

        /*
         * Return the latest persisted version.
         */
        return $payment->refresh();
    }

    /**
     * Validate payment financial invariants.
     */
    public function assertInvariants(Payment $payment): void
    {
        $amount = (int) $payment->amount;
        $amountPaid = (int) $payment->amount_paid;
        $amountRefunded = (int) $payment->amount_refunded;

        /*
         * Original payment amount must be positive.
         */
        if ($amount <= 0) {
            throw new RuntimeException(
                'Payment amount must be greater than zero.'
            );
        }

        /*
         * Paid amount cannot be negative.
         */
        if ($amountPaid < 0) {
            throw new RuntimeException(
                'Payment amount_paid cannot be negative.'
            );
        }

        /*
         * Refunded amount cannot be negative.
         */
        if ($amountRefunded < 0) {
            throw new RuntimeException(
                'Payment amount_refunded cannot be negative.'
            );
        }

        /*
         * Cannot pay more than the original payment.
         */
        if ($amountPaid > $amount) {
            throw new RuntimeException(
                'Payment amount_paid cannot exceed amount.'
            );
        }

        /*
         * Cannot refund more than the original payment.
         */
        if ($amountRefunded > $amount) {
            throw new RuntimeException(
                'Payment amount_refunded cannot exceed amount.'
            );
        }

        /*
         * Cannot refund more than has actually been paid.
         */
        if ($amountRefunded > $amountPaid) {
            throw new RuntimeException(
                'Payment amount_refunded cannot exceed amount_paid.'
            );
        }

        /*
         * PAID means the entire payment amount has been received.
         */
        if (
            $payment->status === PaymentStatus::PAID
            && $amountPaid !== $amount
        ) {
            throw new RuntimeException(
                'A PAID payment must have amount_paid equal to amount.'
            );
        }

        /*
         * Fully REFUNDED means the entire paid amount has been refunded.
         */
        if (
            $payment->status === PaymentStatus::REFUNDED
            && $amountRefunded !== $amount
        ) {
            throw new RuntimeException(
                'A REFUNDED payment must have amount_refunded equal to amount.'
            );
        }
    }

    /**
     * Allowed payment state transitions.
     *
     * UNKNOWN is an exceptional recovery state.
     */
    private function allowedTransitions(
        PaymentStatus $from
    ): array {
        return match ($from) {
            PaymentStatus::CREATED => [
                PaymentStatus::INITIATED,
                PaymentStatus::CANCELLED,
                PaymentStatus::EXPIRED,
            ],

            PaymentStatus::INITIATED => [
                PaymentStatus::PROCESSING,
                PaymentStatus::PENDING,
                PaymentStatus::FAILED,
                PaymentStatus::CANCELLED,
                PaymentStatus::UNKNOWN,
            ],

            PaymentStatus::PROCESSING => [
                PaymentStatus::PAID,
                PaymentStatus::PENDING,
                PaymentStatus::FAILED,
                PaymentStatus::UNKNOWN,
            ],

            PaymentStatus::PENDING => [
                PaymentStatus::PROCESSING,
                PaymentStatus::PAID,
                PaymentStatus::FAILED,
                PaymentStatus::CANCELLED,
                PaymentStatus::UNKNOWN,
            ],

            PaymentStatus::UNKNOWN => [
                PaymentStatus::PROCESSING,
                PaymentStatus::PAID,
                PaymentStatus::FAILED,
                PaymentStatus::PENDING,
            ],

            PaymentStatus::FAILED => [
                PaymentStatus::INITIATED,
                PaymentStatus::PROCESSING,
                PaymentStatus::UNKNOWN,
            ],

            PaymentStatus::PAID => [
                PaymentStatus::REFUND_PENDING,
                PaymentStatus::PARTIALLY_REFUNDED,
                PaymentStatus::REFUNDED,
            ],

            PaymentStatus::REFUND_PENDING => [
                PaymentStatus::PARTIALLY_REFUNDED,
                PaymentStatus::REFUNDED,
                PaymentStatus::PAID,
                PaymentStatus::UNKNOWN,
            ],

            PaymentStatus::PARTIALLY_REFUNDED => [
                PaymentStatus::REFUND_PENDING,
                PaymentStatus::REFUNDED,
                PaymentStatus::UNKNOWN,
            ],

            PaymentStatus::REFUNDED => [
                PaymentStatus::UNKNOWN,
            ],

            PaymentStatus::CANCELLED => [],

            PaymentStatus::EXPIRED => [
                PaymentStatus::INITIATED,
            ],
        };
    }
}