<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentProvider;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Services\PaymentStateService;
use App\Payments\GatewayResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RazorpaySmokeTest extends Command
{
    protected $signature = 'payments:razorpay-smoke
                            {--amount=50000 : Amount in smallest currency unit}
                            {--currency=INR : Currency}';

    protected $description = 'Create a Razorpay TEST order through the payment gateway';

    public function handle(
        GatewayResolver $gatewayResolver,
        PaymentStateService $paymentStateService
    ): int {
        $provider = PaymentProvider::query()
            ->where('code', 'razorpay')
            ->where('is_active', true)
            ->first();

        if (!$provider) {
            $this->error('Active Razorpay provider not found.');
            return self::FAILURE;
        }

        $amount = (int) $this->option('amount');
        $currency = strtoupper((string) $this->option('currency'));

        if ($amount <= 0) {
            $this->error('Amount must be greater than zero.');
            return self::FAILURE;
        }

        $payment = null;
        $attempt = null;

        try {
            DB::transaction(function () use (
                &$payment,
                &$attempt,
                $provider,
                $amount,
                $currency,
                $paymentStateService
            ) {
                $payment = Payment::create([
                    'uuid' => (string) Str::uuid(),
                    'merchant_reference' => 'SMOKE-' . strtoupper(Str::random(16)),
                    'order_reference' => 'SMOKE-' . strtoupper(Str::random(12)),
                    'amount' => $amount,
                    'amount_paid' => 0,
                    'amount_refunded' => 0,
                    'currency' => $currency,
                    'status' => PaymentStatus::CREATED,
                    'payment_method' => 'upi',
                    'description' => 'Razorpay TEST smoke payment',
                    'metadata' => [
                        'source' => 'razorpay_smoke_test',
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

                $paymentStateService->transition(
                    $payment,
                    PaymentStatus::INITIATED
                );
            });

            $this->info("Local payment created: {$payment->uuid}");
            $this->info("Attempt: {$attempt->attempt_uuid}");

            $gateway = $gatewayResolver->resolve($provider);

            $response = $gateway->createPayment(
                $payment->fresh(),
                $attempt->fresh()
            );

            if (!$response->success) {
                $this->error(
                    'Razorpay order creation failed: ' .
                    ($response->message ?? 'Unknown error')
                );

                return self::FAILURE;
            }

            $attempt->refresh();

            $attempt->provider_order_id = $response->providerOrderId;
            $attempt->response_payload = $response->data;
            $attempt->status = PaymentAttemptStatus::INITIATED;
            $attempt->save();

            $this->newLine();

            $this->info('✓ Razorpay TEST order created');
            $this->line("Local Payment UUID : {$payment->uuid}");
            $this->line("Attempt UUID        : {$attempt->attempt_uuid}");
            $this->line("Razorpay Order ID   : {$response->providerOrderId}");

            $this->newLine();

            $this->warn(
                'This creates a TEST order only. It does NOT charge a customer.'
            );

            return self::SUCCESS;

        } catch (Throwable $e) {
            report($e);

            $this->error('Razorpay smoke test failed.');
            $this->line($e->getMessage());

            return self::FAILURE;
        }
    }
}