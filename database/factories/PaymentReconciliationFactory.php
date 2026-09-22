<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentProvider;
use App\Models\PaymentReconciliation;
use App\Payments\Enums\ReconciliationResolutionStatus;
use App\Payments\Enums\ReconciliationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentReconciliationFactory extends Factory
{
    protected $model = PaymentReconciliation::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),

            'payment_attempt_id' => PaymentAttempt::factory(),

            'payment_provider_id' => PaymentProvider::factory(),

            'reconciliation_uuid' => (string) Str::uuid(),

            'provider_transaction_id' => null,

            'provider_payment_id' => null,

            'provider_order_id' => null,

            'local_status' => null,

            'provider_status' => null,

            'local_amount' => null,

            'provider_amount' => null,

            'local_currency' => null,

            'provider_currency' => null,

            'status' => ReconciliationStatus::UNKNOWN,

            'mismatch_type' => null,

            'resolution_status' =>
                ReconciliationResolutionStatus::PENDING,

            'local_data' => null,

            'provider_data' => null,

            'resolution_note' => null,

            'detected_at' => now(),

            'resolved_at' => null,
        ];
    }
}