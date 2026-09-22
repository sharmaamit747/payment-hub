<?php

namespace App\Payments\Enums;

enum TransactionType: string
{
    case SALE = 'sale';
    case AUTHORIZE = 'authorize';
    case CAPTURE = 'capture';
    case VOID = 'void';
    case REFUND = 'refund';
    case CHARGEBACK = 'chargeback';
    case REVERSAL = 'reversal';
}