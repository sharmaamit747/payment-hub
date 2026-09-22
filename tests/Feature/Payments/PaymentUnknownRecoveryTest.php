<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentProvider;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\DTOs\GatewayPaymentResponse;
use App\Payments\DTOs\GatewayRefundResponse;
use App\Payments\DTOs\GatewayVerificationResponse;
use App\Payments\DTOs\GatewayWebhookEvent;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\TransactionStatus;
use App\Payments\Enums\TransactionType;
use App\Payments\GatewayResolver;
use App\Payments\Jobs\VerifyPaymentAttemptJob;
use App\Payments\Services\PaymentTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentUnknownRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_payment_is_recovered_as_paid(): void
    {
        $provider = PaymentProvider::factory()->create([
            'code' => 'test_gateway',
            'is_active' => true,
        ]);

        $payment = Payment::factory()->create([
            'amount' => 50000,
            'amount_paid' => 0,
            'amount_refunded' => 0,
            'currency' => 'INR',
            'status' => PaymentStatus::UNKNOWN,
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_id' => $payment->id,
            'payment_provider_id' => $provider->id,
            'provider_payment_id' => 'pay_unknown_001',
            'amount' => 50000,
            'status' => PaymentAttemptStatus::UNKNOWN,
        ]);

        $gateway = new class implements PaymentGateway {
            public function createPayment(
                Payment $payment,
                PaymentAttempt $attempt
            ): GatewayPaymentResponse {
                throw new \RuntimeException('Not used.');
            }

            public function verifyPayment(
                PaymentAttempt $attempt
            ): GatewayVerificationResponse {
                return new GatewayVerificationResponse(
                    success: true,
                    status: 'paid',
                    providerPaymentId: 'pay_unknown_001',
                    message: 'Payment captured.',
                    data: [
                        'amount' => 50000,
                        'currency' => 'INR',
                    ],
                );
            }

            public function refund(
                Payment $payment,
                int $amount,
                string $idempotencyKey
            ): GatewayRefundResponse {
                throw new \RuntimeException('Not used.');
            }

            public function verifyWebhook(
                Request $request
            ): bool {
                return true;
            }

            public function parseWebhook(
                Request $request
            ): GatewayWebhookEvent {
                throw new \RuntimeException('Not used.');
            }

            public function getPaymentDetails(
                PaymentAttempt $attempt
            ): GatewayVerificationResponse {
                throw new \RuntimeException('Not used.');
            }

            public function verifyRefund(
                PaymentRefund $refund
            ): GatewayRefundResponse {
                throw new \RuntimeException('Not used.');
            }
        };

        /*
         * Bind our fake gateway to the test container.
         */
        $this->app->bind(
            \App\Payments\Gateways\TestGateway::class,
            fn () => $gateway
        );

        config([
            'payments.gateways.test_gateway' =>
                \App\Payments\Gateways\TestGateway::class,
        ]);

        /*
         * Run the verification job directly.
         */
        $job = new VerifyPaymentAttemptJob(
            attemptId: $attempt->id
        );

        $job->handle(
            app(GatewayResolver::class),
            app(PaymentTransactionService::class)
        );

        /*
         * Reload everything from DB.
         */
        $payment->refresh();
        $attempt->refresh();

        /*
         * Payment must become PAID.
         */
        $this->assertSame(
            PaymentStatus::PAID,
            $payment->status
        );

        /*
         * Full amount must be recorded as paid.
         */
        $this->assertSame(
            50000,
            (int) $payment->amount_paid
        );

        /*
         * Attempt must become SUCCEEDED.
         */
        $this->assertSame(
            PaymentAttemptStatus::SUCCEEDED,
            $attempt->status
        );

        /*
         * Exactly one financial transaction.
         */
        $this->assertSame(
            1,
            PaymentTransaction::query()
                ->where('payment_id', $payment->id)
                ->count()
        );

        $transaction = PaymentTransaction::query()
            ->where('payment_id', $payment->id)
            ->firstOrFail();

        $this->assertSame(
            'pay_unknown_001',
            $transaction->provider_transaction_id
        );

        $this->assertSame(
            TransactionType::SALE,
            $transaction->type
        );

        $this->assertSame(
            TransactionStatus::SUCCEEDED,
            $transaction->status
        );

        $this->assertSame(
            50000,
            (int) $transaction->amount
        );
    }
}