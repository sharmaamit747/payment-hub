<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\PaymentWebhookController;
use App\Models\PaymentAttempt;
use App\Models\PaymentProvider;
use App\Payments\Jobs\ProcessPaymentWebhookJob;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class FakePaymentCapture extends Command
{
    protected $signature = 'payments:fake-capture
                            {payment : Payment UUID}';

    protected $description = 'Simulate a successful payment through the real webhook pipeline';

    public function handle(): int
    {
        try {
            $paymentUuid = (string) $this->argument('payment');

            $provider = PaymentProvider::query()
                ->where('code', 'fake')
                ->where('is_active', true)
                ->first();

            if (!$provider) {
                $this->error('Active Fake provider not found.');

                return self::FAILURE;
            }

            $attempt = PaymentAttempt::query()
                ->whereHas('payment', function ($query) use ($paymentUuid) {
                    $query->where('uuid', $paymentUuid);
                })
                ->where('payment_provider_id', $provider->id)
                ->latest('id')
                ->first();

            if (!$attempt) {
                $this->error(
                    "No Fake payment attempt found for payment [{$paymentUuid}]."
                );

                return self::FAILURE;
            }

            $payment = $attempt->payment;

            if (!$payment) {
                $this->error('Payment not found.');

                return self::FAILURE;
            }

            if (!$attempt->provider_order_id) {
                $this->error('Payment attempt does not have a provider order ID.');

                return self::FAILURE;
            }

            $providerPaymentId =
                'fake_payment_' . Str::lower(Str::random(20));

            $eventId =
                'fake_event_' . Str::lower(Str::random(20));

            $payload = [
                'id' => $eventId,

                'event' => 'payment.captured',

                'payload' => [
                    'payment' => [
                        'entity' => [
                            'id' => $providerPaymentId,
                            'order_id' => $attempt->provider_order_id,
                            'amount' => (int) $payment->amount,
                            'currency' => strtoupper($payment->currency),
                            'status' => 'captured',
                            'method' => 'test',
                            'description' => $payment->description,
                        ],
                    ],
                ],
            ];

            $request = Request::create(
                "/api/webhooks/{$provider->code}",
                'POST',
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_X_FAKE_EVENT_ID' => $eventId,
                    'HTTP_X_RAZORPAY_SIGNATURE' => 'fake-signature',
                ],
                json_encode($payload, JSON_THROW_ON_ERROR)
            );

            $controller = app(PaymentWebhookController::class);

            $response = $controller->__invoke(
                $request,
                $provider->code
            );

            $this->line(
                'Webhook HTTP status: ' . $response->getStatusCode()
            );

            $body = json_decode(
                $response->getContent(),
                true
            );

            $this->line(
                'Webhook response: ' .
                json_encode($body, JSON_PRETTY_PRINT)
            );

            if ($response->getStatusCode() !== 200) {
                return self::FAILURE;
            }

            /*
             * Run the same queued job synchronously for this local
             * smoke test so we do not need a separate queue worker.
             */
            $webhookId = \App\Models\PaymentWebhook::query()
                ->where('payment_provider_id', $provider->id)
                ->where('provider_event_id', $eventId)
                ->value('id');

            if (!$webhookId) {
                $this->error('Webhook record was not created.');

                return self::FAILURE;
            }

            (new ProcessPaymentWebhookJob($webhookId))->handle();

            $payment->refresh();
            $attempt->refresh();

            $this->newLine();

            $this->info('✓ Fake payment capture processed');

            $this->line(
                'Payment status : ' . $payment->status->value
            );

            $this->line(
                'Attempt status : ' . $attempt->status->value
            );

            $this->line(
                'Provider payment : ' .
                ($attempt->provider_payment_id ?? 'null')
            );

            $this->line(
                'Amount paid : ' . $payment->amount_paid
            );

            return self::SUCCESS;

        } catch (Throwable $e) {
            report($e);

            $this->error('Fake capture failed.');
            $this->line($e->getMessage());

            return self::FAILURE;
        }
    }
}