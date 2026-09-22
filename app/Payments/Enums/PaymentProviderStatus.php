<?php

namespace App\Payments\Enums;

enum PaymentProviderStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}