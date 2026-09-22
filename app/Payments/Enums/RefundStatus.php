<?php

namespace App\Payments\Enums;

enum RefundStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case UNKNOWN = 'unknown';
}