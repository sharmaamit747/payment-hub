<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Payments\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),

            'merchant_reference' =>
                'MERCHANT-' . strtoupper(Str::random(12)),

            'order_reference' =>
                'ORDER-' . strtoupper(Str::random(12)),

            'user_id' => null,

            'amount' => 50000,

            'amount_paid' => 0,

            'amount_refunded' => 0,

            'currency' => 'INR',

            'status' => PaymentStatus::CREATED,

            'payment_method' => 'upi',

            'description' => 'Test payment',

            'metadata' => [
                'source' => 'automated-test',
            ],

            'paid_at' => null,

            'expires_at' => null,

            'version' => 1,
        ];
    }
}