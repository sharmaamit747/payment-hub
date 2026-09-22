<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentProvider;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Services\PaymentProcessingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class FakePaymentSmokeTest extends Command
{
    protected $signature = 'payments:fake-smoke
                            {--amount=50000 : Amount in smallest currency unit}
                            {--currency=INR : Currency}';

    protected $description = 'Run payment processing against the Fake payment gateway';

    public function handle(
        PaymentProcessingService $processingService
    ): int {
        $provider = PaymentProvider::query()
            ->where('code', 'fake')
            ->where('is_active', true)
            ->first();

        if (!$provider) {
            $this->error('Active Fake payment provider not found.');

            return self::FAILURE;
        }

        $amount = (int) $this->option('amount');
        $currency = strtoupper((string) $this->option('currency'));

        if ($amount <= 0) {
            $this->error('Amount must be greater than zero.');

            return self::FAILURE;
        }

        try {
            [$payment, $attempt] = DB::transaction(function () use ($provider, $amount, $currency) {
                $payment = Payment::create([
                    'uuid' => (string) Str::uuid(),
                    'merchant_reference' => 'FAKE-' . strtoupper(Str::random(16)),
                    'order_reference' => 'FAKE-' . strtoupper(Str::random(12)),
                    'amount' => $amount,
                    'amount_paid' => 0,
                    'amount_refunded' => 0,
                    'currency' => $currency,
                    'status' => PaymentStatus::CREATED,
                    'payment_method' => 'test',
                    'description' => 'Fake payment smoke test',
                    'metadata' => [
                        'source' => 'fake_smoke_test',
                    ],
                ]);

                $attempt = PaymentAttempt::create([
                    'payment_id' => $payment->id,
                    'payment_provider_id' => $provider->id,
                    'attempt_uuid' => (string) Str::uuid(),
                    'amount' => $amount,
                    'status' => PaymentAttemptStatus::CREATED,
                    'started_at' => now(),
                ]);

                return [$payment, $attempt];
            });

            $this->info("Payment: {$payment->uuid}");
            $this->info("Attempt: {$attempt->attempt_uuid}");

            /*
             * Your existing processing service should handle:
             *
             * CREATED
             *   ↓
             * INITIATED
             *   ↓
             * provider gateway
             */
            $result = $processingService->process($attempt);

            $attempt->refresh();
            $payment->refresh();

            $this->newLine();

            $this->info('✓ Fake gateway processing completed');

            $this->line(
                'Payment status : ' . $payment->status->value
            );

            $this->line(
                'Attempt status : ' . $attempt->status->value
            );

            $this->line(
                'Provider order : ' . ($attempt->provider_order_id ?? 'null')
            );

            $this->line(
                'Provider payment : ' . ($attempt->provider_payment_id ?? 'null')
            );

            return self::SUCCESS;

        } catch (Throwable $e) {
            report($e);

            $this->error('Fake payment smoke test failed.');
            $this->line($e->getMessage());

            return self::FAILURE;
        }
    }
}