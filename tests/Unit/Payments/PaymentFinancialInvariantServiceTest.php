<?php

namespace Tests\Unit\Payments;

use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Payments\Services\PaymentFinancialInvariantService;
use RuntimeException;
use Tests\TestCase;

class PaymentFinancialInvariantServiceTest extends TestCase
{
    public function test_paid_amount_cannot_exceed_payment_amount(): void
    {
        $payment = new Payment([
            'amount' => 50000,
            'amount_paid' => 60000,
            'amount_refunded' => 0,
        ]);

        $this->expectException(RuntimeException::class);

        app(PaymentFinancialInvariantService::class)
            ->assertPayment($payment);
    }

    public function test_refunded_amount_cannot_exceed_payment_amount(): void
    {
        $payment = new Payment([
            'amount' => 50000,
            'amount_paid' => 50000,
            'amount_refunded' => 60000,
        ]);

        $this->expectException(RuntimeException::class);

        app(PaymentFinancialInvariantService::class)
            ->assertPayment($payment);
    }

    public function test_refunded_amount_cannot_exceed_paid_amount(): void
    {
        $payment = new Payment([
            'amount' => 50000,
            'amount_paid' => 30000,
            'amount_refunded' => 40000,
        ]);

        $this->expectException(RuntimeException::class);

        app(PaymentFinancialInvariantService::class)
            ->assertPayment($payment);
    }

    public function test_valid_payment_passes(): void
    {
        $payment = new Payment([
            'amount' => 50000,
            'amount_paid' => 50000,
            'amount_refunded' => 20000,
        ]);

        app(PaymentFinancialInvariantService::class)
            ->assertPayment($payment);

        $this->assertTrue(true);
    }

    public function test_refund_cannot_exceed_payment(): void
    {
        $payment = new Payment([
            'amount' => 50000,
            'amount_paid' => 50000,
            'amount_refunded' => 0,
            'currency' => 'INR',
        ]);

        $refund = new PaymentRefund([
            'amount' => 60000,
            'currency' => 'INR',
        ]);

        $this->expectException(RuntimeException::class);

        app(PaymentFinancialInvariantService::class)
            ->assertRefund($refund, $payment);
    }
}