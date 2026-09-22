<?php

namespace App\Payments\StateMachines;

use App\Payments\Enums\PaymentAttemptStatus;
use InvalidArgumentException;

class PaymentAttemptStateMachine
{
    /**
     * @return array<string, PaymentAttemptStatus[]>
     */
    private function transitions(): array
    {
        return [
            PaymentAttemptStatus::CREATED->value => [
                PaymentAttemptStatus::INITIATED,
                PaymentAttemptStatus::CANCELLED,
            ],

            PaymentAttemptStatus::INITIATED->value => [
                PaymentAttemptStatus::PROCESSING,
                PaymentAttemptStatus::PENDING,
                PaymentAttemptStatus::FAILED,
                PaymentAttemptStatus::CANCELLED,
                PaymentAttemptStatus::UNKNOWN,
            ],

            PaymentAttemptStatus::PROCESSING->value => [
                PaymentAttemptStatus::SUCCEEDED,
                PaymentAttemptStatus::PENDING,
                PaymentAttemptStatus::FAILED,
                PaymentAttemptStatus::UNKNOWN,
            ],

            PaymentAttemptStatus::PENDING->value => [
                PaymentAttemptStatus::PROCESSING,
                PaymentAttemptStatus::SUCCEEDED,
                PaymentAttemptStatus::FAILED,
                PaymentAttemptStatus::CANCELLED,
                PaymentAttemptStatus::UNKNOWN,
            ],

            PaymentAttemptStatus::UNKNOWN->value => [
                PaymentAttemptStatus::PROCESSING,
                PaymentAttemptStatus::PENDING,
                PaymentAttemptStatus::SUCCEEDED,
                PaymentAttemptStatus::FAILED,
            ],

            PaymentAttemptStatus::FAILED->value => [
                PaymentAttemptStatus::INITIATED,
                PaymentAttemptStatus::UNKNOWN,
            ],

            PaymentAttemptStatus::CANCELLED->value => [],

            PaymentAttemptStatus::EXPIRED->value => [],
            
            PaymentAttemptStatus::SUCCEEDED->value => [],
        ];
    }

    public function canTransition(
        PaymentAttemptStatus $from,
        PaymentAttemptStatus $to
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
        PaymentAttemptStatus $from,
        PaymentAttemptStatus $to
    ): PaymentAttemptStatus {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid payment attempt state transition: %s → %s',
                    $from->value,
                    $to->value
                )
            );
        }

        return $to;
    }
}