<?php

namespace App\Payments\Enums;

enum ReconciliationResolutionStatus: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case RESOLVED = 'resolved';
    case MANUAL_REVIEW = 'manual_review';
    case IGNORED = 'ignored';
}