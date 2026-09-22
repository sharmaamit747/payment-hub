<?php

namespace Tests\Unit\Payments;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentProvider;
use App\Models\PaymentRefund;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\RefundStatus;
use App\Payments\Services\PaymentRefundStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentRefundStateServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createRefund(
        RefundStatus $status
    ): PaymentRefund {
        $provider = PaymentProvider::create([
            'code' => 'test-' . Str::lower(Str::random(8)),
            'name' => 'Test Provider',
            'is_active' => true,
            'priority' => 1,
            'config' => null,
            'version' => 1,
        ]);

        $payment = Payment::create([
            'uuid' => (string) Str::uuid(),
            'merchant_reference' => 'MERCHANT-' . Str::random(12),
            'order_reference' => 'ORDER-' . Str::random(12),
            'user_id' => null,
            'amount' => 10000,
            'amount_paid' => 10000,
            'amount_refunded' => 0,
            'currency' => 'INR',
            'status' => PaymentStatus::PAID,
            'payment_method' => 'upi',
            'description' => 'Test payment',
            'metadata' => [],
            'paid_at' => now(),
            'expires_at' => null,
            'version' => 1,
        ]);

        $attempt = PaymentAttempt::create([
            'payment_id' => $payment->id,
            'payment_provider_id' => $provider->id,
            'attempt_uuid' => (string) Str::uuid(),
            'provider_payment_id' => 'pay_' . Str::random(12),
            'provider_order_id' => 'order_' . Str::random(12),
            'idempotency_key' => 'attempt-' . Str::random(20),
            'amount' => 10000,
            'status' => PaymentAttemptStatus::SUCCEEDED,
            'request_payload' => null,
            'response_payload' => null,
            'failure_code' => null,
            'failure_reason' => null,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        return PaymentRefund::create([
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'payment_provider_id' => $provider->id,
            'refund_uuid' => (string) Str::uuid(),
            'provider_refund_id' => null,
            'idempotency_key' => 'refund-' . Str::random(20),
            'amount' => 5000,
            'currency' => 'INR',
            'status' => $status,
            'reason' => 'Test refund',
            'metadata' => [],
            'failure_code' => null,
            'failure_reason' => null,
            'processed_at' => null,
        ]);
    }

    public function test_pending_can_move_to_processing(): void
    {
        $refund = PaymentRefund::factory()->create([
            'status' => RefundStatus::PENDING,
        ]);

        $service = app(PaymentRefundStateService::class);

        $result = $service->transition(
            $refund,
            RefundStatus::PROCESSING
        );

        $this->assertSame(
            RefundStatus::PROCESSING,
            $result->status
        );

        $this->assertSame(
            RefundStatus::PROCESSING,
            $refund->status
        );

        /*
         * Transition changes state but does not persist it.
         * Persistence belongs to the caller's transaction.
         */
        $refund->save();

        $refund->refresh();

        $this->assertSame(
            RefundStatus::PROCESSING,
            $refund->status
        );
    }

    public function test_processing_can_move_to_unknown(): void
    {
        $refund = PaymentRefund::factory()->create([
            'status' => RefundStatus::PROCESSING,
        ]);

        $service = app(PaymentRefundStateService::class);

        $result = $service->transition(
            $refund,
            RefundStatus::UNKNOWN
        );

        $this->assertSame(
            RefundStatus::UNKNOWN,
            $result->status
        );

        $this->assertSame(
            RefundStatus::UNKNOWN,
            $refund->status
        );

        $refund->save();

        $refund->refresh();

        $this->assertSame(
            RefundStatus::UNKNOWN,
            $refund->status
        );
    }

    public function test_succeeded_refund_cannot_be_processed_again(): void
    {
        $refund = $this->createRefund(RefundStatus::SUCCEEDED);

        $service = app(PaymentRefundStateService::class);

        $this->expectException(\RuntimeException::class);

        $service->transition(
            $refund,
            RefundStatus::PROCESSING
        );
    }
}