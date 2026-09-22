<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentProvider;
use App\Payments\Enums\PaymentAttemptStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentAttemptFactory extends Factory
{
    protected $model = PaymentAttempt::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),

            'payment_provider_id' => PaymentProvider::factory(),

            'attempt_uuid' => (string) Str::uuid(),

            'provider_payment_id' => null,

            'provider_order_id' => null,

            'idempotency_key' =>
                'attempt-' . Str::lower(Str::random(24)),

            'amount' => 50000,

            'status' => PaymentAttemptStatus::CREATED,

            'request_payload' => null,

            'response_payload' => null,

            'failure_code' => null,

            'failure_reason' => null,

            'started_at' => null,

            'completed_at' => null,
        ];
    }
}