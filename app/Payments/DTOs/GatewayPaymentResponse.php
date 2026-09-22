<?php

namespace App\Payments\DTOs;

final readonly class GatewayPaymentResponse
{
    public function __construct(
        public bool $success,
        public string $status,
        public ?string $providerPaymentId = null,
        public ?string $providerOrderId = null,
        public ?string $message = null,
        public array $data = [],
    ) {
    }
}