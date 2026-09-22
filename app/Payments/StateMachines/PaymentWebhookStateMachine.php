<?php

namespace App\Payments\StateMachines;

use App\Payments\Enums\WebhookStatus;
use InvalidArgumentException;

class PaymentWebhookStateMachine
{
    /**
     * @return array<string, WebhookStatus[]>
     */
    private function transitions(): array
    {
        return [
            WebhookStatus::RECEIVED->value => [
                WebhookStatus::PROCESSING,
                WebhookStatus::IGNORED,
            ],

            WebhookStatus::PROCESSING->value => [
                WebhookStatus::PROCESSED,
                WebhookStatus::FAILED,
            ],

            WebhookStatus::FAILED->value => [
                WebhookStatus::PROCESSING,
                WebhookStatus::IGNORED,
            ],

            WebhookStatus::PROCESSED->value => [],

            WebhookStatus::IGNORED->value => [],
        ];
    }

    public function canTransition(
        WebhookStatus $from,
        WebhookStatus $to
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
        WebhookStatus $from,
        WebhookStatus $to
    ): WebhookStatus {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid webhook state transition: %s → %s',
                    $from->value,
                    $to->value
                )
            );
        }

        return $to;
    }
}