<?php

namespace Tests\Unit\Payments;

use App\Models\Payment;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Services\PaymentStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PaymentStateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_payment_transition_is_allowed(): void
    {
        $payment = Payment::factory()->create([
            'amount' => 50000,
            'amount_paid' => 0,
            'amount_refunded' => 0,
            'status' => PaymentStatus::CREATED,
        ]);

        $service = app(PaymentStateService::class);

        $service->transition(
            $payment,
            PaymentStatus::INITIATED
        );

        $this->assertSame(
            PaymentStatus::INITIATED,
            $payment->fresh()->status
        );
    }

    public function test_invalid_payment_transition_is_rejected(): void
    {
        $payment = Payment::factory()->create([
            'amount' => 50000,
            'amount_paid' => 0,
            'amount_refunded' => 0,
            'status' => PaymentStatus::INITIATED,
        ]);

        $this->expectException(RuntimeException::class);

        app(PaymentStateService::class)->transition(
            $payment,
            PaymentStatus::REFUNDED
        );
    }

    public function test_paid_payment_requires_full_amount_paid(): void
    {
        $payment = Payment::factory()->make([
            'amount' => 50000,
            'amount_paid' => 40000,
            'amount_refunded' => 0,
            'status' => PaymentStatus::PAID,
        ]);

        $this->expectException(RuntimeException::class);

        app(PaymentStateService::class)
            ->assertInvariants($payment);
    }

    public function test_refunded_amount_cannot_exceed_paid_amount(): void
    {
        $payment = Payment::factory()->make([
            'amount' => 50000,
            'amount_paid' => 30000,
            'amount_refunded' => 40000,
            'status' => PaymentStatus::PARTIALLY_REFUNDED,
        ]);

        $this->expectException(RuntimeException::class);

        app(PaymentStateService::class)
            ->assertInvariants($payment);
    }
}