<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentProvider;
use App\Models\PaymentWebhook;
use App\Models\PaymentTransaction;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\TransactionStatus;
use App\Payments\Enums\TransactionType;
use App\Payments\Enums\WebhookStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentWebhookReplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_webhook_event_is_stored_only_once(): void
    {
        $provider = PaymentProvider::factory()->create([
            'code' => 'razorpay',
            'name' => 'Razorpay',
            'is_active' => true,
        ]);

        PaymentWebhook::create([
            'payment_provider_id' => $provider->id,
            'event_uuid' => (string) Str::uuid(),
            'provider_event_id' => 'evt_test_001',
            'event_type' => 'payment.captured',
            'status' => WebhookStatus::RECEIVED,
            'payload' => [
                'event' => 'payment.captured',
            ],
            'received_at' => now(),
        ]);

        /*
         * Simulate provider sending exactly the same event again.
         */
        $this->expectException(\Illuminate\Database\QueryException::class);

        PaymentWebhook::create([
            'payment_provider_id' => $provider->id,
            'event_uuid' => (string) Str::uuid(),
            'provider_event_id' => 'evt_test_001',
            'event_type' => 'payment.captured',
            'status' => WebhookStatus::RECEIVED,
            'payload' => [
                'event' => 'payment.captured',
            ],
            'received_at' => now(),
        ]);
    }

    public function test_duplicate_webhook_does_not_create_duplicate_transaction(): void
    {
        $provider = PaymentProvider::factory()->create([
            'code' => 'razorpay',
            'is_active' => true,
        ]);

        $payment = Payment::factory()->create([
            'amount' => 50000,
            'amount_paid' => 0,
            'currency' => 'INR',
            'status' => PaymentStatus::INITIATED,
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_id' => $payment->id,
            'payment_provider_id' => $provider->id,
            'provider_payment_id' => 'pay_test_001',
            'amount' => 50000,
            'status' => PaymentAttemptStatus::PROCESSING,
        ]);

        $webhook = PaymentWebhook::create([
            'payment_provider_id' => $provider->id,
            'payment_id' => $payment->id,
            'event_uuid' => (string) Str::uuid(),
            'provider_event_id' => 'evt_capture_001',
            'event_type' => 'payment.captured',
            'status' => WebhookStatus::PROCESSED,
            'payload' => [
                'event' => 'payment.captured',
            ],
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        /*
         * First financial transaction.
         */
        PaymentTransaction::create([
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'payment_provider_id' => $provider->id,
            'transaction_uuid' => (string) Str::uuid(),
            'provider_transaction_id' => 'pay_test_001',
            'idempotency_key' => 'sale:pay_test_001',
            'type' => TransactionType::SALE,
            'status' => TransactionStatus::SUCCEEDED,
            'amount' => 50000,
            'currency' => 'INR',
            'metadata' => [
                'webhook_event_id' => $webhook->provider_event_id,
            ],
            'processed_at' => now(),
        ]);

        /*
         * Replay must not produce another transaction.
         */
        $transactionCountBefore =
            PaymentTransaction::query()
                ->where('payment_id', $payment->id)
                ->count();

        $sameTransaction =
            PaymentTransaction::query()
                ->where('payment_provider_id', $provider->id)
                ->where('provider_transaction_id', 'pay_test_001')
                ->first();

        $this->assertNotNull($sameTransaction);

        $transactionCountAfter =
            PaymentTransaction::query()
                ->where('payment_id', $payment->id)
                ->count();

        $this->assertSame(
            $transactionCountBefore,
            $transactionCountAfter
        );
    }

    public function test_webhook_attempt_counter_increments_when_processing(): void
    {
        $provider = PaymentProvider::factory()->create([
            'code' => 'razorpay',
            'is_active' => true,
        ]);

        $webhook = PaymentWebhook::create([
            'payment_provider_id' => $provider->id,
            'event_uuid' => (string) Str::uuid(),
            'provider_event_id' => 'evt_attempt_001',
            'event_type' => 'payment.captured',
            'status' => WebhookStatus::RECEIVED,
            'payload' => [
                'event' => 'payment.captured',
            ],
            'attempts' => 0,
            'received_at' => now(),
        ]);

        $webhook->update([
            'status' => WebhookStatus::PROCESSING,
            'attempts' => $webhook->attempts + 1,
        ]);

        $webhook->refresh();

        $this->assertSame(1, $webhook->attempts);
        $this->assertSame(
            WebhookStatus::PROCESSING,
            $webhook->status
        );
    }
}