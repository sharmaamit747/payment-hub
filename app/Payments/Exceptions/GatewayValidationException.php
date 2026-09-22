<?php

namespace App\Payments\Exceptions;

class GatewayValidationException extends GatewayException
{
    public function __construct(
        string $message = 'Payment gateway validation failed.'
    ) {
        parent::__construct($message);
    }
}