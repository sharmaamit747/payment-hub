<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentProvider;
use App\Models\PaymentTransaction;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Services\PaymentTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentTransactionIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_provider_transaction_cannot_be_recorded_twice(): void
    {
        $provider = PaymentProvider::create([
            'code' => 'fake',
            'name' => 'Fake Provider',
            'is_active' => true,
            'priority' => 1,
            'config' => null,
            'version' => 1,
        ]);

        $payment = Payment::create([
            'uuid' => (string) Str::uuid(),
            'merchant_reference' => 'MERCHANT-' . Str::random(12),
            'order_reference' => 'ORDER-' . Str::random(12),
            'amount' => 50000,
            'amount_paid' => 0,
            'amount_refunded' => 0,
            'currency' => 'INR',
            'status' => PaymentStatus::PROCESSING,
            'payment_method' => 'upi',
            'metadata' => [],
            'version' => 1,
        ]);

        $attempt = PaymentAttempt::create([
            'payment_id' => $payment->id,
            'payment_provider_id' => $provider->id,
            'attempt_uuid' => (string) Str::uuid(),
            'provider_payment_id' => 'pay_fake_123',
            'provider_order_id' => 'order_fake_123',
            'idempotency_key' => 'attempt-' . Str::random(20),
            'amount' => 50000,
            'status' => PaymentAttemptStatus::PROCESSING,
        ]);

        $service = app(PaymentTransactionService::class);

        $transaction1 = $service->recordSuccessfulSale(
            payment: $payment,
            attempt: $attempt,
            providerTransactionId: 'pay_fake_123',
            amount: 50000,
            currency: 'INR',
            metadata: [
                'source' => 'test',
            ]
        );

        $transaction2 = $service->recordSuccessfulSale(
            payment: $payment->fresh(),
            attempt: $attempt->fresh(),
            providerTransactionId: 'pay_fake_123',
            amount: 50000,
            currency: 'INR',
            metadata: [
                'source' => 'duplicate-test',
            ]
        );

        $this->assertSame(
            $transaction1->id,
            $transaction2->id
        );

        $this->assertSame(
            1,
            PaymentTransaction::query()
                ->where('payment_provider_id', $provider->id)
                ->where(
                    'provider_transaction_id',
                    'pay_fake_123'
                )
                ->count()
        );

        $payment->refresh();

        $this->assertSame(
            50000,
            (int) $payment->amount_paid
        );

        $this->assertSame(
            PaymentStatus::PAID,
            $payment->status
        );
    }
}