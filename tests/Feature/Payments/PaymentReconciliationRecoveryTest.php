<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentReconciliation;
use App\Models\PaymentProvider;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\ReconciliationResolutionStatus;
use App\Payments\Enums\ReconciliationStatus;
use App\Payments\Services\PaymentReconciliationRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\PaymentTransaction;

class PaymentReconciliationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_provider_payment_is_recovered(): void
    {
        $provider = PaymentProvider::factory()->create([
            'code' => 'test-provider',
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
            'provider_payment_id' => 'pay_test_123',
            'provider_order_id' => 'order_test_123',
            'amount' => 50000,
            'status' => PaymentAttemptStatus::UNKNOWN,
        ]);

        $reconciliation = PaymentReconciliation::factory()->create([
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'payment_provider_id' => $provider->id,
            'provider_payment_id' => 'pay_test_123',
            'provider_order_id' => 'order_test_123',
            'provider_transaction_id' => 'pay_test_123',
            'provider_amount' => 50000,
            'provider_currency' => 'INR',
            'status' => ReconciliationStatus::MISMATCH,
            'resolution_status' => ReconciliationResolutionStatus::IN_PROGRESS,
        ]);

        /*
         * We are testing recovery itself, so mock the transaction service.
         */
        $transactionService = $this->mock(
            \App\Payments\Services\PaymentTransactionService::class
        );

        $transactionService
            ->shouldReceive('recordSuccessfulSale')
            ->once()
            ->andReturnUsing(function (Payment $payment, PaymentAttempt $attempt, string $providerTransactionId, int $amount, string $currency, array $metadata) {
                $payment->amount_paid = $amount;
                $payment->paid_at = now();
                $payment->status = PaymentStatus::PAID;
                $payment->save();

                $attempt->status = PaymentAttemptStatus::SUCCEEDED;
                $attempt->save();

                $transaction = \Mockery::mock(PaymentTransaction::class);

                $transaction->shouldReceive('getAttribute')
                    ->with('id')
                    ->andReturn(1);

                $transaction->shouldReceive('getAttribute')
                    ->with('transaction_uuid')
                    ->andReturn('test-transaction');

                return $transaction;
            });

        $service = app(PaymentReconciliationRecoveryService::class);

        $result = $service->recoverSuccessfulPayment($reconciliation);

        $payment->refresh();
        $attempt->refresh();
        $result->refresh();

        $this->assertSame(
            PaymentStatus::PAID,
            $payment->status
        );

        $this->assertSame(
            50000,
            (int) $payment->amount_paid
        );

        $this->assertSame(
            PaymentAttemptStatus::SUCCEEDED,
            $attempt->status
        );

        $this->assertSame(
            ReconciliationStatus::MATCHED,
            $result->status
        );

        $this->assertSame(
            ReconciliationResolutionStatus::RESOLVED,
            $result->resolution_status
        );
    }

    public function test_amount_mismatch_goes_to_manual_review(): void
    {
        $provider = PaymentProvider::factory()->create([
            'code' => 'test-provider',
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
            'provider_payment_id' => 'pay_test_456',
            'amount' => 50000,
            'status' => PaymentAttemptStatus::UNKNOWN,
        ]);

        $reconciliation = PaymentReconciliation::factory()->create([
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'payment_provider_id' => $provider->id,
            'provider_payment_id' => 'pay_test_456',
            'provider_transaction_id' => 'pay_test_456',
            'provider_amount' => 49000,
            'provider_currency' => 'INR',
            'status' => ReconciliationStatus::MISMATCH,
            'resolution_status' => ReconciliationResolutionStatus::IN_PROGRESS,
        ]);

        $service = app(PaymentReconciliationRecoveryService::class);

        $result = $service->recoverSuccessfulPayment($reconciliation);

        $result->refresh();

        $this->assertSame(
            ReconciliationStatus::MISMATCH,
            $result->status
        );

        $this->assertSame(
            ReconciliationResolutionStatus::MANUAL_REVIEW,
            $result->resolution_status
        );

        $payment->refresh();

        $this->assertNotSame(
            PaymentStatus::PAID,
            $payment->status
        );
    }

    public function test_currency_mismatch_goes_to_manual_review(): void
    {
        $provider = PaymentProvider::factory()->create([
            'code' => 'test-provider',
        ]);

        $payment = Payment::factory()->create([
            'amount' => 50000,
            'amount_paid' => 0,
            'currency' => 'INR',
            'status' => PaymentStatus::UNKNOWN,
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_id' => $payment->id,
            'payment_provider_id' => $provider->id,
            'provider_payment_id' => 'pay_currency_test',
            'amount' => 50000,
            'status' => PaymentAttemptStatus::UNKNOWN,
        ]);

        $reconciliation = PaymentReconciliation::factory()->create([
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'payment_provider_id' => $provider->id,
            'provider_payment_id' => 'pay_currency_test',
            'provider_transaction_id' => 'pay_currency_test',
            'provider_amount' => 50000,
            'provider_currency' => 'USD',
            'status' => ReconciliationStatus::MISMATCH,
            'resolution_status' => ReconciliationResolutionStatus::IN_PROGRESS,
        ]);

        $service = app(PaymentReconciliationRecoveryService::class);

        $result = $service->recoverSuccessfulPayment($reconciliation);

        $result->refresh();

        $this->assertSame(
            ReconciliationResolutionStatus::MANUAL_REVIEW,
            $result->resolution_status
        );

        $payment->refresh();

        $this->assertSame(
            PaymentStatus::UNKNOWN,
            $payment->status
        );
    }

    public function test_already_paid_payment_is_only_marked_resolved(): void
    {
        $provider = PaymentProvider::factory()->create([
            'code' => 'test-provider',
        ]);

        $payment = Payment::factory()->create([
            'amount' => 50000,
            'amount_paid' => 50000,
            'amount_refunded' => 0,
            'currency' => 'INR',
            'status' => PaymentStatus::PAID,
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_id' => $payment->id,
            'payment_provider_id' => $provider->id,
            'provider_payment_id' => 'pay_already_paid',
            'amount' => 50000,
            'status' => PaymentAttemptStatus::SUCCEEDED,
        ]);

        $reconciliation = PaymentReconciliation::factory()->create([
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'payment_provider_id' => $provider->id,
            'provider_payment_id' => 'pay_already_paid',
            'provider_transaction_id' => 'pay_already_paid',
            'provider_amount' => 50000,
            'provider_currency' => 'INR',
            'status' => ReconciliationStatus::MATCHED,
            'resolution_status' => ReconciliationResolutionStatus::IN_PROGRESS,
        ]);

        $service = app(PaymentReconciliationRecoveryService::class);

        $result = $service->recoverSuccessfulPayment($reconciliation);

        $result->refresh();

        $this->assertSame(
            ReconciliationStatus::MATCHED,
            $result->status
        );

        $this->assertSame(
            ReconciliationResolutionStatus::RESOLVED,
            $result->resolution_status
        );

        $payment->refresh();

        $this->assertSame(50000, (int) $payment->amount_paid);
        $this->assertSame(PaymentStatus::PAID, $payment->status);
    }

    public function test_paid_payment_is_not_downgraded_when_provider_reports_failure(): void
    {
        $provider = PaymentProvider::factory()->create([
            'code' => 'test-provider',
        ]);

        $payment = Payment::factory()->create([
            'amount' => 50000,
            'amount_paid' => 50000,
            'currency' => 'INR',
            'status' => PaymentStatus::PAID,
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_id' => $payment->id,
            'payment_provider_id' => $provider->id,
            'provider_payment_id' => 'pay_failure_conflict',
            'amount' => 50000,
            'status' => PaymentAttemptStatus::SUCCEEDED,
        ]);

        $reconciliation = PaymentReconciliation::factory()->create([
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'payment_provider_id' => $provider->id,
            'provider_payment_id' => 'pay_failure_conflict',
            'provider_transaction_id' => 'pay_failure_conflict',
            'provider_amount' => 50000,
            'provider_currency' => 'INR',
            'status' => ReconciliationStatus::MISMATCH,
            'resolution_status' => ReconciliationResolutionStatus::IN_PROGRESS,
        ]);

        $service = app(PaymentReconciliationRecoveryService::class);

        $result = $service->recoverFailedPayment($reconciliation);

        $result->refresh();
        $payment->refresh();

        $this->assertSame(
            PaymentStatus::PAID,
            $payment->status
        );

        $this->assertSame(
            ReconciliationResolutionStatus::MANUAL_REVIEW,
            $result->resolution_status
        );
    }

    public function test_refunded_payment_is_not_resurrected_as_paid(): void
    {
        $provider = PaymentProvider::factory()->create([
            'code' => 'test-provider',
        ]);

        $payment = Payment::factory()->create([
            'amount' => 50000,
            'amount_paid' => 50000,
            'amount_refunded' => 50000,
            'currency' => 'INR',
            'status' => PaymentStatus::REFUNDED,
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_id' => $payment->id,
            'payment_provider_id' => $provider->id,
            'provider_payment_id' => 'pay_refunded',
            'amount' => 50000,
            'status' => PaymentAttemptStatus::SUCCEEDED,
        ]);

        $reconciliation = PaymentReconciliation::factory()->create([
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'payment_provider_id' => $provider->id,
            'provider_payment_id' => 'pay_refunded',
            'provider_transaction_id' => 'pay_refunded',
            'provider_amount' => 50000,
            'provider_currency' => 'INR',
            'status' => ReconciliationStatus::MISMATCH,
            'resolution_status' => ReconciliationResolutionStatus::IN_PROGRESS,
        ]);

        $service = app(PaymentReconciliationRecoveryService::class);

        $result = $service->recoverSuccessfulPayment($reconciliation);

        $result->refresh();
        $payment->refresh();

        $this->assertSame(
            PaymentStatus::REFUNDED,
            $payment->status
        );

        $this->assertSame(
            ReconciliationResolutionStatus::MANUAL_REVIEW,
            $result->resolution_status
        );
    }
}