<?php

namespace App\Payments\Enums;

enum WebhookStatus: string
{
    case RECEIVED = 'received';
    case PROCESSING = 'processing';
    case PROCESSED = 'processed';
    case FAILED = 'failed';
    case IGNORED = 'ignored';
}