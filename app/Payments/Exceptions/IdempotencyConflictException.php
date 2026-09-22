<?php

namespace App\Payments\Exceptions;

use RuntimeException;

class IdempotencyConflictException extends RuntimeException
{
}