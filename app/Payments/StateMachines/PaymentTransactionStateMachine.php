<?php

namespace App\Payments\StateMachines;

use App\Payments\Enums\TransactionStatus;
use InvalidArgumentException;

class PaymentTransactionStateMachine
{
    /**
     * @return array<string, TransactionStatus[]>
     */
    private function transitions(): array
    {
        return [
            TransactionStatus::PENDING->value => [
                TransactionStatus::PROCESSING,
                TransactionStatus::SUCCEEDED,
                TransactionStatus::FAILED,
                TransactionStatus::CANCELLED,
                TransactionStatus::UNKNOWN,
            ],

            TransactionStatus::PROCESSING->value => [
                TransactionStatus::SUCCEEDED,
                TransactionStatus::FAILED,
                TransactionStatus::UNKNOWN,
            ],

            TransactionStatus::UNKNOWN->value => [
                TransactionStatus::PROCESSING,
                TransactionStatus::SUCCEEDED,
                TransactionStatus::FAILED,
            ],

            TransactionStatus::FAILED->value => [
                TransactionStatus::PENDING,
                TransactionStatus::UNKNOWN,
            ],

            TransactionStatus::SUCCEEDED->value => [],

            TransactionStatus::CANCELLED->value => [],
        ];
    }

    public function canTransition(
        TransactionStatus $from,
        TransactionStatus $to
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
        TransactionStatus $from,
        TransactionStatus $to
    ): TransactionStatus {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid transaction state transition: %s → %s',
                    $from->value,
                    $to->value
                )
            );
        }

        return $to;
    }
}