<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentProvider;
use App\Models\PaymentRefund;
use App\Payments\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentRefundFactory extends Factory
{
    protected $model = PaymentRefund::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'payment_attempt_id' => PaymentAttempt::factory(),
            'payment_provider_id' => PaymentProvider::factory(),

            'refund_uuid' => (string) Str::uuid(),

            'provider_refund_id' => null,

            'idempotency_key' =>
                'refund-' . $this->faker->unique()->uuid(),

            'amount' => 10000,

            'currency' => 'INR',

            'status' => RefundStatus::PENDING,

            'reason' => null,

            'metadata' => [],

            'failure_code' => null,

            'failure_reason' => null,

            'processed_at' => null,
        ];
    }
}