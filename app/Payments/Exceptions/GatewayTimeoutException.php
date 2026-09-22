<?php

namespace App\Payments\Exceptions;

class GatewayTimeoutException extends GatewayException
{
    public function __construct(
        string $message = 'Payment gateway request timed out.'
    ) {
        parent::__construct($message);
    }
}