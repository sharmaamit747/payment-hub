<?php

namespace App\Payments\StateMachines;

use App\Payments\Enums\PaymentStatus;
use InvalidArgumentException;

class PaymentStateMachine
{
    /**
     * @return array<string, PaymentStatus[]>
     */
    private function transitions(): array
    {
        return [
            PaymentStatus::CREATED->value => [
                PaymentStatus::INITIATED,
                PaymentStatus::CANCELLED,
                PaymentStatus::EXPIRED,
            ],

            PaymentStatus::INITIATED->value => [
                PaymentStatus::PROCESSING,
                PaymentStatus::PENDING,
                PaymentStatus::FAILED,
                PaymentStatus::CANCELLED,
                PaymentStatus::UNKNOWN,
            ],

            PaymentStatus::PROCESSING->value => [
                PaymentStatus::PAID,
                PaymentStatus::PENDING,
                PaymentStatus::FAILED,
                PaymentStatus::UNKNOWN,
            ],

            PaymentStatus::PENDING->value => [
                PaymentStatus::PROCESSING,
                PaymentStatus::PAID,
                PaymentStatus::FAILED,
                PaymentStatus::CANCELLED,
                PaymentStatus::UNKNOWN,
            ],

            PaymentStatus::UNKNOWN->value => [
                PaymentStatus::PROCESSING,
                PaymentStatus::PAID,
                PaymentStatus::FAILED,
                PaymentStatus::PENDING,
            ],

            PaymentStatus::PAID->value => [
                PaymentStatus::REFUND_PENDING,
                PaymentStatus::PARTIALLY_REFUNDED,
                PaymentStatus::REFUNDED,
            ],

            PaymentStatus::REFUND_PENDING->value => [
                PaymentStatus::PARTIALLY_REFUNDED,
                PaymentStatus::REFUNDED,
                PaymentStatus::PAID,
                PaymentStatus::UNKNOWN,
            ],

            PaymentStatus::PARTIALLY_REFUNDED->value => [
                PaymentStatus::REFUND_PENDING,
                PaymentStatus::REFUNDED,
                PaymentStatus::UNKNOWN,
            ],

            PaymentStatus::REFUNDED->value => [
                PaymentStatus::UNKNOWN,
            ],

            PaymentStatus::FAILED->value => [
                PaymentStatus::INITIATED,
                PaymentStatus::PROCESSING,
                PaymentStatus::UNKNOWN,
            ],

            PaymentStatus::CANCELLED->value => [],

            PaymentStatus::EXPIRED->value => [
                PaymentStatus::INITIATED,
            ],
        ];
    }

    public function canTransition(
        PaymentStatus $from,
        PaymentStatus $to
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
        PaymentStatus $from,
        PaymentStatus $to
    ): PaymentStatus {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid payment state transition: %s → %s',
                    $from->value,
                    $to->value
                )
            );
        }

        return $to;
    }
}