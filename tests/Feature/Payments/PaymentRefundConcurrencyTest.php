<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentProvider;
use App\Models\PaymentRefund;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\RefundStatus;
use App\Payments\Services\PaymentRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PaymentRefundConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function createPaidPayment(): array
    {
        $provider = PaymentProvider::factory()->create([
            'code' => 'razorpay',
            'is_active' => true,
        ]);

        $payment = Payment::factory()->create([
            'amount' => 50000,
            'amount_paid' => 50000,
            'amount_refunded' => 0,
            'currency' => 'INR',
            'status' => PaymentStatus::PAID,
            'paid_at' => now(),
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_id' => $payment->id,
            'payment_provider_id' => $provider->id,
            'provider_payment_id' => 'pay_test_001',
            'amount' => 50000,
            'status' => PaymentAttemptStatus::SUCCEEDED,
        ]);

        return [$provider, $payment, $attempt];
    }

    public function test_pending_refund_is_reserved_against_future_refund(): void
    {
        [$provider, $payment, $attempt] = $this->createPaidPayment();

        PaymentRefund::create([
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'payment_provider_id' => $provider->id,
            'refund_uuid' => (string) Str::uuid(),
            'idempotency_key' => 'refund-existing-001',
            'amount' => 30000,
            'currency' => 'INR',
            'status' => RefundStatus::PENDING,
        ]);

        $service = app(PaymentRefundService::class);

        $refund = $service->createRefund(
            payment: $payment,
            amount: 20000,
            idempotencyKey: 'refund-new-000001',
        );

        $this->assertSame(
            20000,
            (int) $refund->amount
        );

        $this->assertSame(
            RefundStatus::PENDING,
            $refund->status
        );
    }

    public function test_refund_cannot_exceed_amount_after_pending_refund_reservation(): void
    {
        [$provider, $payment, $attempt] = $this->createPaidPayment();

        PaymentRefund::create([
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'payment_provider_id' => $provider->id,
            'refund_uuid' => (string) Str::uuid(),
            'idempotency_key' => 'refund-existing-002',
            'amount' => 30000,
            'currency' => 'INR',
            'status' => RefundStatus::PROCESSING,
        ]);

        $service = app(PaymentRefundService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Refund amount exceeds the available refundable amount'
        );

        $service->createRefund(
            payment: $payment,
            amount: 25000,
            idempotencyKey: 'refund-over-limit-001',
        );
    }

    public function test_unknown_refund_is_reserved(): void
    {
        [$provider, $payment, $attempt] = $this->createPaidPayment();

        PaymentRefund::create([
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'payment_provider_id' => $provider->id,
            'refund_uuid' => (string) Str::uuid(),
            'idempotency_key' => 'refund-unknown-001',
            'amount' => 40000,
            'currency' => 'INR',
            'status' => RefundStatus::UNKNOWN,
        ]);

        $service = app(PaymentRefundService::class);

        $this->expectException(RuntimeException::class);

        $service->createRefund(
            payment: $payment,
            amount: 11000,
            idempotencyKey: 'refund-over-unknown',
        );
    }

    public function test_successful_refund_reduces_remaining_refundable_amount(): void
    {
        [$provider, $payment, $attempt] = $this->createPaidPayment();

        PaymentRefund::create([
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'payment_provider_id' => $provider->id,
            'refund_uuid' => (string) Str::uuid(),
            'idempotency_key' => 'refund-success-001',
            'amount' => 30000,
            'currency' => 'INR',
            'status' => RefundStatus::SUCCEEDED,
            'processed_at' => now(),
        ]);

        $payment->refresh();

        $service = app(PaymentRefundService::class);

        $refund = $service->createRefund(
            payment: $payment,
            amount: 20000,
            idempotencyKey: 'refund-final-001',
        );

        $this->assertSame(20000, (int) $refund->amount);

        $this->assertSame(
            2,
            PaymentRefund::query()
                ->where('payment_id', $payment->id)
                ->count()
        );
    }

    public function test_duplicate_refund_idempotency_key_returns_same_refund(): void
    {
        [$provider, $payment, $attempt] = $this->createPaidPayment();

        $service = app(PaymentRefundService::class);

        $first = $service->createRefund(
            payment: $payment,
            amount: 10000,
            idempotencyKey: 'refund-idempotency-001',
        );

        $second = $service->createRefund(
            payment: $payment,
            amount: 10000,
            idempotencyKey: 'refund-idempotency-001',
        );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertSame(
            1,
            PaymentRefund::query()
                ->where('idempotency_key', 'refund-idempotency-001')
                ->count()
        );
    }
}