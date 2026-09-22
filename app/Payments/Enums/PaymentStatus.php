<?php

namespace App\Payments\Enums;

enum PaymentStatus: string
{
    case CREATED = 'created';
    case INITIATED = 'initiated';
    case PROCESSING = 'processing';
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case REFUND_PENDING = 'refund_pending';
    case PARTIALLY_REFUNDED = 'partially_refunded';
    case REFUNDED = 'refunded';
    case UNKNOWN = 'unknown';
}