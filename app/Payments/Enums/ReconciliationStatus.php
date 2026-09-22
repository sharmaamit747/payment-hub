<?php

namespace App\Payments\Enums;

enum ReconciliationStatus: string
{
    case MATCHED = 'matched';
    case MISMATCH = 'mismatch';
    case UNKNOWN = 'unknown';
}