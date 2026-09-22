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
use Tests\TestCase;

class PaymentUnknownRefundRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function createUnknownRefund(): array
    {
        $provider = PaymentProvider::factory()->create([
            'code' => 'test_gateway',
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
            'provider_payment_id' => 'pay_test_refund_001',
            'amount' => 50000,
            'status' => PaymentAttemptStatus::SUCCEEDED,
        ]);

        $refund = PaymentRefund::create([
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'payment_provider_id' => $provider->id,
            'refund_uuid' => (string) Str::uuid(),
            'provider_refund_id' => null,
            'idempotency_key' => 'refund-unknown-test-001',
            'amount' => 20000,
            'currency' => 'INR',
            'status' => RefundStatus::UNKNOWN,
        ]);

        return [
            $provider,
            $payment,
            $attempt,
            $refund,
        ];
    }

    public function test_unknown_refund_is_recovered_as_succeeded(): void
    {
        [
            $provider,
            $payment,
            $attempt,
            $refund,
        ] = $this->createUnknownRefund();

        /*
         * TestGateway returns a successful verification.
         */
        config([
            'payments.gateways.test_gateway' =>
                \App\Payments\Gateways\TestGateway::class,
        ]);

        $service = app(PaymentRefundService::class);

        /*
         * We need the test gateway to return a refund result.
         *
         * For the first implementation, this test verifies the
         * database/state transition path through the existing
         * service contract.
         */
        $this->app->bind(
            \App\Payments\Gateways\TestGateway::class,
            function () {
                return new \Tests\Feature\Payments\Support\TestRefundGateway();
            }
        );

        $result = $service->verifyUnknownRefund(
            $refund->fresh()
        );

        $result->refresh();

        $this->assertSame(
            RefundStatus::SUCCEEDED,
            $result->status
        );

        $this->assertSame(
            'refund_provider_001',
            $result->provider_refund_id
        );

        $payment->refresh();

        $this->assertSame(
            20000,
            (int) $payment->amount_refunded
        );

        $this->assertSame(
            PaymentStatus::PARTIALLY_REFUNDED,
            $payment->status
        );
    }
}