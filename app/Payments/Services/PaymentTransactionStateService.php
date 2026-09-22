<?php

namespace App\Payments\Services;

use App\Models\PaymentTransaction;
use App\Payments\Enums\TransactionStatus;
use RuntimeException;

class PaymentTransactionStateService
{
    public function transition(
        PaymentTransaction $transaction,
        TransactionStatus $to
    ): PaymentTransaction {
        $transaction->refresh();

        $from = $transaction->status;

        if ($from === $to) {
            return $transaction;
        }

        if (!in_array(
            $to,
            $this->allowedTransitions($from),
            true
        )) {
            throw new RuntimeException(
                "Invalid transaction state transition [{$from->value}] -> [{$to->value}]."
            );
        }

        $transaction->status = $to;
        $transaction->save();

        return $transaction->refresh();
    }

    private function allowedTransitions(
        TransactionStatus $from
    ): array {
        return match ($from) {
            TransactionStatus::PENDING => [
                TransactionStatus::PROCESSING,
                TransactionStatus::SUCCEEDED,
                TransactionStatus::FAILED,
                TransactionStatus::CANCELLED,
                TransactionStatus::UNKNOWN,
            ],

            TransactionStatus::PROCESSING => [
                TransactionStatus::SUCCEEDED,
                TransactionStatus::FAILED,
                TransactionStatus::UNKNOWN,
            ],

            TransactionStatus::UNKNOWN => [
                TransactionStatus::PROCESSING,
                TransactionStatus::SUCCEEDED,
                TransactionStatus::FAILED,
            ],

            TransactionStatus::FAILED => [
                TransactionStatus::PENDING,
                TransactionStatus::UNKNOWN,
            ],

            TransactionStatus::SUCCEEDED,
            TransactionStatus::CANCELLED => [],
        };
    }
}