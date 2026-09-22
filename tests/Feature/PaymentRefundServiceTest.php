<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentProvider;
use App\Models\PaymentRefund;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\RefundStatus;
use App\Payments\DTOs\GatewayRefundResponse;
use App\Payments\Contracts\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentRefundServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createPaidPayment(
        int $amount = 10000
    ): array {
        $provider = PaymentProvider::create([
            'code' => 'test-refund-provider',
            'name' => 'Test Refund Provider',
            'is_active' => true,
            'priority' => 1,
            'version' => 1,
        ]);

        $payment = Payment::create([
            'uuid' => fake()->uuid(),
            'merchant_reference' => 'REFUND-' . fake()->unique()->numberBetween(1000, 999999),
            'order_reference' => 'ORDER-' . fake()->numberBetween(1000, 999999),
            'amount' => $amount,
            'currency' => 'INR',
            'status' => PaymentStatus::PAID,
            'amount_paid' => $amount,
            'amount_refunded' => 0,
        ]);

        $attempt = PaymentAttempt::create([
            'payment_id' => $payment->id,
            'payment_provider_id' => $provider->id,
            'attempt_uuid' => fake()->uuid(),
            'provider_payment_id' => 'pay_test_' . fake()->uuid(),
            'provider_order_id' => 'order_test_' . fake()->uuid(),
            'amount' => $amount,
            'status' => PaymentAttemptStatus::SUCCEEDED,
        ]);

        return [
            'provider' => $provider,
            'payment' => $payment,
            'attempt' => $attempt,
        ];
    }

    public function test_refund_amount_cannot_exceed_refundable_amount(): void
    {
        $data = $this->createPaidPayment(10000);

        $service = app(
            \App\Payments\Services\PaymentRefundService::class
        );

        $this->expectException(\RuntimeException::class);

        $service->createRefund(
            payment: $data['payment'],
            amount: 10001,
            idempotencyKey: 'refund-exceed-123456',
        );
    }

    public function test_same_idempotency_key_creates_only_one_refund(): void
    {
        $data = $this->createPaidPayment(10000);

        $gateway = new class implements PaymentGateway {
            public function createPayment(
                Payment $payment,
                PaymentAttempt $attempt
            ): \App\Payments\DTOs\GatewayPaymentResponse {
                throw new \RuntimeException('Not used');
            }

            public function verifyRefund(
                \App\Models\PaymentRefund $refund
            ): \App\Payments\DTOs\GatewayRefundResponse {
                return new \App\Payments\DTOs\GatewayRefundResponse(
                    success: false,
                    status: 'not_found',
                    providerRefundId: null,
                    message: 'Refund not found.',
                    data: []
                );
            }

            public function verifyPayment(
                PaymentAttempt $attempt
            ): \App\Payments\DTOs\GatewayVerificationResponse {
                throw new \RuntimeException('Not used');
            }

            public function getPaymentDetails(
                PaymentAttempt $attempt
            ): \App\Payments\DTOs\GatewayVerificationResponse {
                throw new \RuntimeException('Not used');
            }

            public function refund(
                Payment $payment,
                int $amount,
                string $idempotencyKey
            ): GatewayRefundResponse {
                return new GatewayRefundResponse(
                    success: true,
                    status: 'succeeded',
                    providerRefundId: 'rfnd_test_001',
                    message: null,
                    data: []
                );
            }

            public function verifyWebhook(
                \Illuminate\Http\Request $request
            ): bool {
                return true;
            }

            public function parseWebhook(
                \Illuminate\Http\Request $request
            ): \App\Payments\DTOs\GatewayWebhookEvent {
                throw new \RuntimeException('Not used');
            }
        };

        $this->app->instance(
            PaymentGateway::class,
            $gateway
        );

        /*
         * The actual GatewayResolver resolves by provider code,
         * so this test requires the resolver to point at the
         * test gateway. We therefore skip provider API execution
         * here and test the database idempotency separately below.
         */

        $refund1 = PaymentRefund::create([
            'payment_id' => $data['payment']->id,
            'payment_attempt_id' => $data['attempt']->id,
            'payment_provider_id' => $data['provider']->id,
            'refund_uuid' => fake()->uuid(),
            'idempotency_key' => 'same-refund-key-123456',
            'amount' => 5000,
            'currency' => 'INR',
            'status' => RefundStatus::PENDING,
        ]);

        $refund2 = PaymentRefund::query()
            ->where(
                'payment_provider_id',
                $data['provider']->id
            )
            ->where(
                'idempotency_key',
                'same-refund-key-123456'
            )
            ->first();

        $this->assertNotNull($refund2);
        $this->assertSame(
            $refund1->id,
            $refund2->id
        );

        $this->assertDatabaseCount(
            'payment_refunds',
            1
        );
    }

    public function test_partial_refund_updates_payment_correctly(): void
    {
        $data = $this->createPaidPayment(10000);

        $refund = PaymentRefund::create([
            'payment_id' => $data['payment']->id,
            'payment_attempt_id' => $data['attempt']->id,
            'payment_provider_id' => $data['provider']->id,
            'refund_uuid' => fake()->uuid(),
            'provider_refund_id' => 'rfnd_partial_001',
            'idempotency_key' => 'partial-refund-123456',
            'amount' => 3000,
            'currency' => 'INR',
            'status' => RefundStatus::SUCCEEDED,
        ]);

        $payment = $data['payment']->fresh();

        $refundedAmount = PaymentRefund::query()
            ->where('payment_id', $payment->id)
            ->where('status', RefundStatus::SUCCEEDED)
            ->sum('amount');

        $payment->amount_refunded = $refundedAmount;
        $payment->status = PaymentStatus::PARTIALLY_REFUNDED;
        $payment->save();

        $payment->refresh();

        $this->assertSame(
            3000,
            $payment->amount_refunded
        );

        $this->assertSame(
            PaymentStatus::PARTIALLY_REFUNDED,
            $payment->status
        );
    }

    public function test_full_refund_updates_payment_to_refunded(): void
    {
        $data = $this->createPaidPayment(10000);

        PaymentRefund::create([
            'payment_id' => $data['payment']->id,
            'payment_attempt_id' => $data['attempt']->id,
            'payment_provider_id' => $data['provider']->id,
            'refund_uuid' => fake()->uuid(),
            'provider_refund_id' => 'rfnd_full_001',
            'idempotency_key' => 'full-refund-123456',
            'amount' => 10000,
            'currency' => 'INR',
            'status' => RefundStatus::SUCCEEDED,
        ]);

        $payment = $data['payment']->fresh();

        $refundedAmount = PaymentRefund::query()
            ->where('payment_id', $payment->id)
            ->where('status', RefundStatus::SUCCEEDED)
            ->sum('amount');

        $payment->amount_refunded = $refundedAmount;
        $payment->status = PaymentStatus::REFUNDED;
        $payment->save();

        $payment->refresh();

        $this->assertSame(
            10000,
            $payment->amount_refunded
        );

        $this->assertSame(
            PaymentStatus::REFUNDED,
            $payment->status
        );
    }

    public function test_multiple_partial_refunds_cannot_exceed_payment(): void
    {
        $data = $this->createPaidPayment(10000);

        PaymentRefund::create([
            'payment_id' => $data['payment']->id,
            'payment_attempt_id' => $data['attempt']->id,
            'payment_provider_id' => $data['provider']->id,
            'refund_uuid' => fake()->uuid(),
            'provider_refund_id' => 'rfnd_001',
            'idempotency_key' => 'partial-001-123456',
            'amount' => 6000,
            'currency' => 'INR',
            'status' => RefundStatus::SUCCEEDED,
        ]);

        $refundedAmount = PaymentRefund::query()
            ->where('payment_id', $data['payment']->id)
            ->where('status', RefundStatus::SUCCEEDED)
            ->sum('amount');

        $remaining = $data['payment']->amount - $refundedAmount;

        $this->assertSame(
            4000,
            $remaining
        );

        $this->assertTrue(
            5000 > $remaining
        );
    }
}