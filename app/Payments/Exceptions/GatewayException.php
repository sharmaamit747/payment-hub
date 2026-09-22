<?php

namespace App\Payments\Exceptions;

use RuntimeException;
use Throwable;

class GatewayException extends RuntimeException
{
    public function __construct(
        $message = "Payment Gateway error.",
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}