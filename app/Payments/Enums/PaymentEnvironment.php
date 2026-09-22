<?php

namespace App\Payments\Enums;

enum PaymentEnvironment: string
{
    case TEST = 'test';
    case LIVE = 'live';
}