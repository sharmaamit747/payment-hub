<?php

namespace App\Payments\Services;

use App\Models\Payment;
use App\Models\PaymentRefund;
use RuntimeException;

class PaymentFinancialInvariantService
{
    public function assertPayment(Payment $payment): void
    {
        $amount = (int) $payment->amount;
        $amountPaid = (int) $payment->amount_paid;
        $amountRefunded = (int) $payment->amount_refunded;

        if ($amount <= 0) {
            throw new RuntimeException(
                'Payment amount must be greater than zero.'
            );
        }

        if ($amountPaid < 0) {
            throw new RuntimeException(
                'Payment amount_paid cannot be negative.'
            );
        }

        if ($amountRefunded < 0) {
            throw new RuntimeException(
                'Payment amount_refunded cannot be negative.'
            );
        }

        if ($amountPaid > $amount) {
            throw new RuntimeException(
                'Payment amount_paid cannot exceed payment amount.'
            );
        }

        if ($amountRefunded > $amount) {
            throw new RuntimeException(
                'Payment amount_refunded cannot exceed payment amount.'
            );
        }

        if ($amountRefunded > $amountPaid) {
            throw new RuntimeException(
                'Payment amount_refunded cannot exceed amount_paid.'
            );
        }
    }

    public function assertRefund(
        PaymentRefund $refund,
        Payment $payment
    ): void {
        $refundAmount = (int) $refund->amount;
        $paymentAmount = (int) $payment->amount;
        $paymentPaid = (int) $payment->amount_paid;

        if ($refundAmount <= 0) {
            throw new RuntimeException(
                'Refund amount must be greater than zero.'
            );
        }

        if ($refundAmount > $paymentAmount) {
            throw new RuntimeException(
                'Refund amount cannot exceed payment amount.'
            );
        }

        if ($refundAmount > $paymentPaid) {
            throw new RuntimeException(
                'Refund amount cannot exceed paid amount.'
            );
        }

        if (
            strtoupper((string) $refund->currency)
            !== strtoupper((string) $payment->currency)
        ) {
            throw new RuntimeException(
                'Refund currency must match payment currency.'
            );
        }
    }
}