<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Gateway Implementations
    |--------------------------------------------------------------------------
    |
    | Provider code => Gateway implementation class
    |
    */
    'environment' => env('PAYMENT_ENVIRONMENT', 'test'),

    'gateways' => [
        'fake' => \App\Payments\Gateways\Fake\FakePaymentGateway::class,

        'razorpay' => \App\Payments\Gateways\Razorpay\RazorpayGateway::class,

        'stripe' => \App\Payments\Gateways\Stripe\StripeGateway::class,
    ],
];