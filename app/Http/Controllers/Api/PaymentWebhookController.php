<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentProvider;
use App\Models\PaymentWebhook;
use App\Payments\GatewayResolver;
use App\Payments\Jobs\ProcessPaymentWebhookJob;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PaymentWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider
    ): JsonResponse {
        $paymentProvider = PaymentProvider::query()
            ->where('code', strtolower(trim($provider)))
            ->where('is_active', true)
            ->first();

        if (!$paymentProvider) {
            return response()->json([
                'success' => false,
                'message' => 'Payment provider not found or inactive.',
            ], 404);
        }

        try {
            $gateway = app(GatewayResolver::class)
                ->resolve($paymentProvider);

            /*
             * Verify webhook signature BEFORE persisting anything.
             */
            if (!$gateway->verifyWebhook($request)) {
                Log::warning('Invalid payment webhook signature.', [
                    'provider_id' => $paymentProvider->id,
                    'provider' => $paymentProvider->code,
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid webhook signature.',
                ], 400);
            }

            /*
             * Parse provider event.
             */
            $event = $gateway->parseWebhook($request);

            if (!$event->eventId) {
                throw new RuntimeException(
                    'Webhook provider event ID is missing.'
                );
            }

            /*
             * Check duplicate before attempting insert.
             *
             * This is only an optimization.
             *
             * The UNIQUE database constraint remains the actual
             * concurrency protection.
             */
            $existing = PaymentWebhook::query()
                ->where('payment_provider_id', $paymentProvider->id)
                ->where('provider_event_id', $event->eventId)
                ->first();

            if ($existing) {
                /*
                 * Always acknowledge duplicate provider events.
                 *
                 * Otherwise the provider may continuously retry.
                 */
                return response()->json([
                    'success' => true,
                    'message' => 'Webhook already received.',
                ], 200);
            }

            /*
             * Persist only safe/useful headers.
             *
             * NEVER store Authorization, cookies or arbitrary
             * request headers.
             */
            $headers = [
                'x-razorpay-event-id' =>
                    $request->header('X-Razorpay-Event-Id'),

                'x-razorpay-signature' =>
                    $request->header('X-Razorpay-Signature'),

                'content-type' =>
                    $request->header('Content-Type'),

                'user-agent' =>
                    $request->userAgent(),
            ];

            /*
             * Remove null headers.
             */
            $headers = array_filter(
                $headers,
                static fn ($value) => $value !== null
            );

            try {
                $webhook = PaymentWebhook::create([
                    'payment_provider_id' =>
                        $paymentProvider->id,

                    'payment_id' => null,

                    'event_uuid' =>
                        (string) Str::uuid(),

                    'provider_event_id' =>
                        $event->eventId,

                    'event_type' =>
                        $event->eventType,

                    'status' =>
                        \App\Payments\Enums\WebhookStatus::RECEIVED,

                    'payload' =>
                        $request->json()->all(),

                    'headers' =>
                        $headers,

                    'signature' =>
                        $request->header('X-Razorpay-Signature'),

                    'attempts' =>
                        0,

                    'received_at' =>
                        now(),
                ]);
            } catch (QueryException $e) {
                /*
                 * Concurrent duplicate insert.
                 *
                 * Another request won the unique constraint between
                 * our SELECT and INSERT.
                 */
                if (!$this->isDuplicateWebhookException($e)) {
                    throw $e;
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Webhook already received.',
                ], 200);
            }

            /*
             * Queue processing AFTER webhook has been safely persisted.
             *
             * The HTTP request never waits for payment processing.
             */
            ProcessPaymentWebhookJob::dispatch($webhook->id)
                ->onQueue('payments');

            /*
             * Acknowledge immediately.
             */
            return response()->json([
                'success' => true,
                'message' => 'Webhook received.',
            ], 200);

        } catch (Throwable $e) {

            Log::error('Payment webhook processing failed.', [
                'provider_id' => $paymentProvider->id,
                'provider' => $paymentProvider->code,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            /*
             * Do not expose internal exception details.
             */
            return response()->json([
                'success' => false,
                'message' => 'Webhook could not be processed.',
            ], 500);
        }
    }

    private function isDuplicateWebhookException(
        QueryException $exception
    ): bool {
        /*
         * MySQL duplicate key:
         *
         * SQLSTATE[23000]
         * Error 1062
         */
        if ($exception->getCode() === '23000') {
            return str_contains(
                strtolower($exception->getMessage()),
                'duplicate'
            );
        }

        return false;
    }
}