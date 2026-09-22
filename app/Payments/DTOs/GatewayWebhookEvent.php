<?php

namespace App\Payments\DTOs;

final readonly class GatewayWebhookEvent
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public ?string $providerPaymentId = null,
        public ?string $providerOrderId = null,
        public ?string $providerTransactionId = null,
        public ?string $providerRefundId = null,
        public ?string $status = null,
        public ?int $amount = null,
        public ?string $currency = null,
        public array $data = [],
    ) {
    }
}