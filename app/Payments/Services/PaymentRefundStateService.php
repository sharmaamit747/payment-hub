<?php

namespace App\Payments\Services;

use App\Models\PaymentRefund;
use App\Payments\Enums\RefundStatus;
use RuntimeException;

class PaymentRefundStateService
{
    public function transition(
        PaymentRefund $refund,
        RefundStatus $to
    ): PaymentRefund {
        $from = $refund->status;

        if ($from === $to) {
            return $refund;
        }

        $allowed = $this->allowedTransitions($from);

        if (!in_array($to, $allowed, true)) {
            throw new RuntimeException(
                "Invalid refund state transition [{$from->value}] -> [{$to->value}]."
            );
        }

        /*
         * IMPORTANT:
         * Do NOT refresh here.
         *
         * The caller may have already changed fields such as:
         * - provider_refund_id
         * - failure_code
         * - failure_reason
         * - metadata
         * - processed_at
         *
         * Refreshing here would discard those unsaved changes.
         */
        $refund->status = $to;

        return $refund;
    }

    private function allowedTransitions(
        RefundStatus $from
    ): array {
        return match ($from) {
            RefundStatus::PENDING => [
                RefundStatus::PROCESSING,
                RefundStatus::SUCCEEDED,
                RefundStatus::FAILED,
                RefundStatus::CANCELLED,
                RefundStatus::UNKNOWN,
            ],

            RefundStatus::PROCESSING => [
                RefundStatus::SUCCEEDED,
                RefundStatus::FAILED,
                RefundStatus::UNKNOWN,
            ],

            RefundStatus::UNKNOWN => [
                RefundStatus::PROCESSING,
                RefundStatus::PENDING,
                RefundStatus::SUCCEEDED,
                RefundStatus::FAILED,
            ],

            RefundStatus::FAILED => [
                RefundStatus::PENDING,
                RefundStatus::UNKNOWN,
            ],

            RefundStatus::CANCELLED => [],

            RefundStatus::SUCCEEDED => [],

        };
    }
}