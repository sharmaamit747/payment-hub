<?php

namespace App\Payments\Enums;

enum ReconciliationMismatchType: string
{
    case STATUS = 'status';
    case AMOUNT = 'amount';
    case CURRENCY = 'currency';
    case MISSING_LOCAL_TRANSACTION = 'missing_local_transaction';
    case MISSING_PROVIDER_TRANSACTION = 'missing_provider_transaction';
    case DUPLICATE_TRANSACTION = 'duplicate_transaction';
    case UNKNOWN = 'unknown';
}