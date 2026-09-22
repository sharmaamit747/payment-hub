<?php

namespace App\Payments\Exceptions;

class GatewayAuthenticationException extends GatewayException
{
    public function __construct(
        string $message = 'Payment gateway authentication failed.'
    ) {
        parent::__construct($message);
    }
}