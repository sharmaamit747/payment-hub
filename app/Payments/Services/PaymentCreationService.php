<?php

namespace App\Payments\Services;

use App\Models\IdempotencyKey;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Payments\Enums\PaymentAttemptStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Exceptions\IdempotencyConflictException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentCreationService
{
    public function __construct(
        private PaymentRouter $router,
        private IdempotencyService $idempotencyService
    ) {
    }

    public function create(array $data, string $idempotencyKey): array
    {
        // Idempotency key must NOT participate in request hashing.
        unset($data['idempotency_key']);

        $requestHash = $this->idempotencyService->hashRequest($data);

        /*
         * Existing request
         */
        $existing = $this->idempotencyService->get($idempotencyKey);

        if ($existing !== null) {
            $this->idempotencyService->validateExisting(
                $existing,
                $requestHash
            );

            if ($existing->isCompleted() && $existing->payment_id !== null) {
                return [
                    'payment' => Payment::with(['attempts.provider'])
                        ->findOrFail($existing->payment_id),
                    'response_status' => $existing->response_status ?? 201,
                    'replayed' => true,
                ];
            }

            /*
             * A previous process created the payment but crashed before
             * completing the idempotency response.
             */
            if ($existing->payment_id !== null) {
                $payment = Payment::with(['attempts.provider'])
                    ->findOrFail($existing->payment_id);

                return [
                    'payment' => $payment,
                    'response_status' => 201,
                    'replayed' => true,
                ];
            }

            throw new RuntimeException(
                'A request with this idempotency key is already being processed.'
            );
        }

        /*
         * Payment + idempotency record are created in ONE transaction.
         */
        try {
            [$idempotencyRecord, $payment] = DB::transaction(
                function () use ($data, $idempotencyKey, $requestHash) {

                    $idempotencyRecord = IdempotencyKey::create([
                        'key' => $idempotencyKey,
                        'request_hash' => $requestHash,
                        'status' => 'processing',
                        'expires_at' => now()->addDay(),
                    ]);

                    $metadata = $data['metadata'] ?? [];

                    if (
                        isset($data['country_code']) &&
                        !isset($metadata['country_code'])
                    ) {
                        $metadata['country_code'] = $data['country_code'];
                    }

                    $payment = Payment::create([
                        'uuid' => (string) Str::uuid(),
                        'merchant_reference' => $data['merchant_reference'],
                        'order_reference' => $data['order_reference'] ?? null,
                        'user_id' => $data['user_id'] ?? null,
                        'amount' => $data['amount'],
                        'amount_paid' => 0,
                        'amount_refunded' => 0,
                        'currency' => strtoupper($data['currency']),
                        'status' => PaymentStatus::CREATED,
                        'payment_method' => $data['payment_method'] ?? null,
                        'description' => $data['description'] ?? null,
                        'metadata' => $metadata,
                        'expires_at' => $data['expires_at'] ?? null,
                        'version' => 1,
                    ]);

                    $provider = $this->router->route($payment);

                    PaymentAttempt::create([
                        'payment_id' => $payment->id,
                        'payment_provider_id' => $provider->id,
                        'attempt_uuid' => (string) Str::uuid(),
                        'amount' => $payment->amount,
                        'status' => PaymentAttemptStatus::CREATED,
                    ]);

                    /*
                     * Critical:
                     * Link idempotency record to payment BEFORE commit.
                     */
                    $idempotencyRecord->update([
                        'payment_id' => $payment->id,
                    ]);

                    return [
                        $idempotencyRecord,
                        $payment->load(['attempts.provider']),
                    ];
                }
            );
        } catch (\Illuminate\Database\QueryException $e) {

            /*
             * Another concurrent request may have created the same
             * idempotency key.
             */
            $existing = $this->idempotencyService->get($idempotencyKey);

            if ($existing !== null) {
                $this->idempotencyService->validateExisting(
                    $existing,
                    $requestHash
                );

                if ($existing->payment_id !== null) {
                    return [
                        'payment' => Payment::with(['attempts.provider'])
                            ->findOrFail($existing->payment_id),
                        'response_status' => $existing->response_status ?? 201,
                        'replayed' => true,
                    ];
                }

                throw new RuntimeException(
                    'A request with this idempotency key is already being processed.'
                );
            }

            throw $e;
        }

        /*
         * Build the canonical response.
         */
        $responseBody = [
            'success' => true,
            'data' => [
                'payment' => [
                    'uuid' => $payment->uuid,
                    'merchant_reference' => $payment->merchant_reference,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'status' => $payment->status->value,
                    'payment_method' => $payment->payment_method,
                    'attempt' => $payment->attempts->first()
                        ? [
                            'attempt_uuid' =>
                                $payment->attempts->first()->attempt_uuid,
                            'provider' =>
                                $payment->attempts->first()->provider->code,
                            'status' =>
                                $payment->attempts->first()->status->value,
                        ]
                        : null,
                ],
            ],
        ];

        /*
         * Complete idempotency record after successful DB commit.
         */
        $this->idempotencyService->complete(
            $idempotencyRecord,
            $payment->id,
            201,
            $responseBody
        );

        return [
            'payment' => $payment,
            'response_status' => 201,
            'replayed' => false,
        ];
    }
}