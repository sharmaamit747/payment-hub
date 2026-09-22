<?php

namespace App\Payments\Services;

use App\Models\PaymentAttempt;
use App\Payments\Enums\PaymentAttemptStatus;
use RuntimeException;

class PaymentAttemptStateService
{
    public function transition(
        PaymentAttempt $attempt,
        PaymentAttemptStatus $to
    ): PaymentAttempt {
        $attempt->refresh();

        $from = $attempt->status;

        if ($from === $to) {
            return $attempt;
        }

        if (!in_array(
            $to,
            $this->allowedTransitions($from),
            true
        )) {
            throw new RuntimeException(
                "Invalid payment attempt state transition [{$from->value}] -> [{$to->value}]."
            );
        }

        $attempt->status = $to;
        $attempt->save();

        return $attempt->refresh();
    }

    private function allowedTransitions(
        PaymentAttemptStatus $from
    ): array {
        return match ($from) {
            PaymentAttemptStatus::CREATED => [
                PaymentAttemptStatus::INITIATED,
                PaymentAttemptStatus::CANCELLED,
            ],

            PaymentAttemptStatus::INITIATED => [
                PaymentAttemptStatus::PROCESSING,
                PaymentAttemptStatus::PENDING,
                PaymentAttemptStatus::FAILED,
                PaymentAttemptStatus::CANCELLED,
                PaymentAttemptStatus::UNKNOWN,
            ],

            PaymentAttemptStatus::PROCESSING => [
                PaymentAttemptStatus::SUCCEEDED,
                PaymentAttemptStatus::PENDING,
                PaymentAttemptStatus::FAILED,
                PaymentAttemptStatus::UNKNOWN,
            ],

            PaymentAttemptStatus::PENDING => [
                PaymentAttemptStatus::PROCESSING,
                PaymentAttemptStatus::SUCCEEDED,
                PaymentAttemptStatus::FAILED,
                PaymentAttemptStatus::CANCELLED,
                PaymentAttemptStatus::UNKNOWN,
            ],

            PaymentAttemptStatus::UNKNOWN => [
                PaymentAttemptStatus::PROCESSING,
                PaymentAttemptStatus::PENDING,
                PaymentAttemptStatus::SUCCEEDED,
                PaymentAttemptStatus::FAILED,
            ],

            PaymentAttemptStatus::FAILED => [
                PaymentAttemptStatus::INITIATED,
                PaymentAttemptStatus::UNKNOWN,
            ],

            PaymentAttemptStatus::CANCELLED,
            PaymentAttemptStatus::EXPIRED,
            PaymentAttemptStatus::SUCCEEDED => [],
        };
    }
}