<?php

namespace App\Payments\Enums;

enum PaymentAttemptStatus: string
{
    case CREATED = 'created';
    case INITIATED = 'initiated';
    case PROCESSING = 'processing';
    case PENDING = 'pending';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case UNKNOWN = 'unknown';
}