<?php

namespace App\Payments\StateMachines;

use App\Payments\Enums\RefundStatus;
use InvalidArgumentException;

class PaymentRefundStateMachine
{
    /**
     * @return array<string, RefundStatus[]>
     */
    private function transitions(): array
    {
        return [
            RefundStatus::PENDING->value => [
                RefundStatus::PROCESSING,
                RefundStatus::SUCCEEDED,
                RefundStatus::FAILED,
                RefundStatus::CANCELLED,
                RefundStatus::UNKNOWN,
            ],

            RefundStatus::PROCESSING->value => [
                RefundStatus::SUCCEEDED,
                RefundStatus::FAILED,
                RefundStatus::UNKNOWN,
            ],

            /*
             * UNKNOWN means the provider response is uncertain.
             * We must verify before treating the refund as failed.
             */
            RefundStatus::UNKNOWN->value => [
                RefundStatus::PROCESSING,
                RefundStatus::SUCCEEDED,
                RefundStatus::FAILED,
            ],

            /*
             * A failed refund can be retried.
             * The retry should normally create a new financial
             * operation/idempotency key rather than mutating
             * the old successful/failed operation.
             */
            RefundStatus::FAILED->value => [
                RefundStatus::PENDING,
                RefundStatus::UNKNOWN,
            ],

            /*
             * Terminal states.
             */
            RefundStatus::SUCCEEDED->value => [],

            RefundStatus::CANCELLED->value => [],
        ];
    }

    public function canTransition(
        RefundStatus $from,
        RefundStatus $to
    ): bool {
        if ($from === $to) {
            return true;
        }

        return in_array(
            $to,
            $this->transitions()[$from->value] ?? [],
            true
        );
    }

    public function transition(
        RefundStatus $from,
        RefundStatus $to
    ): RefundStatus {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid refund state transition: %s → %s',
                    $from->value,
                    $to->value
                )
            );
        }

        return $to;
    }
}