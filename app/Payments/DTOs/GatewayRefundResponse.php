<?php

namespace App\Payments\DTOs;

final readonly class GatewayRefundResponse
{
    public function __construct(
        public bool $success,
        public string $status,
        public ?string $providerRefundId = null,
        public ?string $message = null,
        public array $data = [],
    ) {
    }
}