<?php

namespace App\Payments\Services;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\RefundStatus;
use App\Payments\GatewayResolver;
use App\Payments\Jobs\VerifyPaymentRefundJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PaymentRefundService
{
    public function __construct(
        private GatewayResolver $gatewayResolver,
        private PaymentStateService $paymentStateService,
        private PaymentRefundStateService $refundStateService,
        private PaymentFinancialInvariantService $invariantService
    ) {
    }

    /**
     * Create a refund request.
     *
     * Important:
     * - Idempotent by provider + idempotency key.
     * - Payment is locked while calculating refundable amount.
     * - Pending/processing/unknown refunds are treated as reserved.
     * - Provider API is NOT called inside the DB transaction.
     */
    public function createRefund(
        Payment $payment,
        int $amount,
        string $idempotencyKey,
        ?string $reason = null,
        array $metadata = []
    ): PaymentRefund {
        if ($amount <= 0) {
            throw new RuntimeException(
                'Refund amount must be greater than zero.'
            );
        }

        if (
            strlen($idempotencyKey) < 16 ||
            strlen($idempotencyKey) > 150
        ) {
            throw new RuntimeException(
                'Invalid refund idempotency key.'
            );
        }

        return DB::transaction(function () use ($payment, $amount, $idempotencyKey, $reason, $metadata) {
            /*
             * Lock payment so concurrent refund requests
             * cannot calculate the same refundable balance.
             */
            $payment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Idempotency check.
             */
            $existing = PaymentRefund::query()
                ->where('payment_provider_id', $this->providerId($payment))
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            /*
             * Payment must be refundable.
             */
            if (
                !in_array(
                    $payment->status,
                    [
                        PaymentStatus::PAID,
                        PaymentStatus::PARTIALLY_REFUNDED,
                    ],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Payment is not eligible for refund.'
                );
            }

            /*
             * Make sure the payment itself is financially valid.
             */
            $this->invariantService->assertPayment($payment);

            /*
             * Do not automatically refund a payment which has
             * an unresolved reconciliation issue.
             */
            $hasUnresolvedReconciliation =
                $payment->reconciliations()
                    ->whereIn('resolution_status', [
                        'pending',
                        'in_progress',
                        'manual_review',
                    ])
                    ->exists();

            if ($hasUnresolvedReconciliation) {
                throw new RuntimeException(
                    'Payment has an unresolved reconciliation issue and cannot be refunded automatically.'
                );
            }

            /*
             * Calculate successful refunds.
             */
            $successfulRefundAmount = (int) $payment->refunds()
                ->where(
                    'status',
                    RefundStatus::SUCCEEDED->value
                )
                ->sum('amount');

            /*
             * Pending/processing/unknown refunds are reserved.
             *
             * UNKNOWN is included because the provider may have
             * processed the refund even though our request timed out.
             */
            $reservedRefundAmount = (int) $payment->refunds()
                ->whereIn('status', [
                    RefundStatus::PENDING->value,
                    RefundStatus::PROCESSING->value,
                    RefundStatus::UNKNOWN->value,
                ])
                ->sum('amount');

            /*
             * Calculate remaining refundable amount.
             */
            $refundableAmount =
                (int) $payment->amount
                - $successfulRefundAmount
                - $reservedRefundAmount;

            if ($amount > $refundableAmount) {
                throw new RuntimeException(
                    "Refund amount exceeds the available refundable amount of [{$refundableAmount}]."
                );
            }

            /*
             * Get provider from latest payment attempt.
             */
            $attempt = PaymentAttempt::query()
                ->where('payment_id', $payment->id)
                ->whereNotNull('provider_payment_id')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (!$attempt) {
                throw new RuntimeException(
                    'No provider payment attempt is available for refund.'
                );
            }

            /*
             * Provider must match payment attempt.
             */
            $providerId = $attempt->payment_provider_id;

            /*
             * Create local refund record.
             */
            $refund = PaymentRefund::create([
                'payment_id' => $payment->id,
                'payment_attempt_id' => $attempt->id,
                'payment_provider_id' => $providerId,
                'refund_uuid' => (string) Str::uuid(),
                'provider_refund_id' => null,
                'idempotency_key' => $idempotencyKey,
                'amount' => $amount,
                'currency' => strtoupper($payment->currency),
                'status' => RefundStatus::PENDING,
                'reason' => $reason,
                'metadata' => $metadata,
            ]);

            /*
             * Validate refund financial invariants.
             */
            $this->invariantService->assertRefund(
                $refund,
                $payment
            );

            /*
             * Payment is now waiting for refund processing.
             */
            $this->paymentStateService->transition(
                $payment,
                PaymentStatus::REFUND_PENDING
            );

            return $refund->fresh();
        });
    }

    /**
     * Process a newly-created refund.
     *
     * External provider call happens OUTSIDE the DB transaction.
     */
    public function processRefund(
        PaymentRefund|int $refund
    ): PaymentRefund {
        $refundId = $refund instanceof PaymentRefund
            ? $refund->id
            : $refund;

        /*
         * Claim the refund.
         *
         * Only PENDING can make a new provider request.
         */
        $claim = DB::transaction(function () use ($refundId) {
            $refund = PaymentRefund::query()
                ->whereKey($refundId)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Already successful/failed.
             */
            if (
                in_array(
                    $refund->status,
                    [
                        RefundStatus::SUCCEEDED,
                        RefundStatus::FAILED,
                    ],
                    true
                )
            ) {
                return [
                    'refund' => $refund,
                    'process' => false,
                ];
            }

            /*
             * UNKNOWN must be verified, not blindly refunded again.
             */
            if ($refund->status === RefundStatus::UNKNOWN) {
                return [
                    'refund' => $refund,
                    'process' => false,
                ];
            }

            /*
             * Another worker may already be processing it.
             */
            if ($refund->status === RefundStatus::PROCESSING) {
                return [
                    'refund' => $refund,
                    'process' => false,
                ];
            }

            /*
             * Only PENDING can initiate a new provider request.
             */
            if ($refund->status !== RefundStatus::PENDING) {
                return [
                    'refund' => $refund,
                    'process' => false,
                ];
            }

            $this->refundStateService->transition(
                $refund,
                RefundStatus::PROCESSING
            );

            return [
                'refund' => $refund->fresh([
                    'payment',
                    'attempt',
                    'provider',
                ]),
                'process' => true,
            ];
        });

        /** @var PaymentRefund $refund */
        $refund = $claim['refund'];

        if (!$claim['process']) {
            return $refund;
        }

        /*
         * Load required relationships.
         */
        $refund->loadMissing([
            'payment',
            'attempt',
            'provider',
        ]);

        $payment = $refund->payment;
        $attempt = $refund->attempt;
        $provider = $refund->provider;

        try {
            /*
             * Resolve provider gateway.
             */
            $gateway = $this->gatewayResolver->resolve(
                $provider
            );

            /*
             * External API call.
             *
             * IMPORTANT:
             * No DB transaction is held here.
             */
            $response = $gateway->refund(
                payment: $payment,
                amount: (int) $refund->amount,
                idempotencyKey: (string) $refund->idempotency_key
            );

            /*
             * Provider explicitly succeeded.
             */
            if ($response->success) {

                if (!$response->providerRefundId) {
                    /*
                     * Provider said success but did not return
                     * a refund ID. We cannot safely assume success.
                     */
                    return $this->markUnknown(
                        $refund,
                        'Provider refund succeeded but provider refund ID was missing.'
                    );
                }

                return DB::transaction(
                    function () use ($refund, $response) {
                        $refund = PaymentRefund::query()
                            ->whereKey($refund->id)
                            ->lockForUpdate()
                            ->firstOrFail();

                        /*
                         * Another process may have already completed it
                         * through webhook.
                         */
                        if (
                            $refund->status ===
                            RefundStatus::SUCCEEDED
                        ) {
                            return $refund;
                        }

                        $refund->provider_refund_id =
                            $response->providerRefundId;

                        $refund->metadata = array_merge(
                            $refund->metadata ?? [],
                            [
                                'provider_response' =>
                                    $response->data,
                                'processed_via' =>
                                    'refund_api',
                            ]
                        );

                        $refund->processed_at = now();

                        $this->refundStateService->transition(
                            $refund,
                            RefundStatus::SUCCEEDED
                        );

                        $refund->save();

                        $refund->load('payment');

                        $this->syncPaymentState(
                            $refund->payment
                        );

                        return $refund->fresh([
                            'payment',
                            'attempt',
                            'provider',
                        ]);
                    }
                );
            }

            /*
             * Provider explicitly rejected the refund.
             *
             * This is safe to mark FAILED because the provider
             * explicitly told us the operation was rejected.
             */
            $status = strtolower(
                (string) $response->status
            );

            if (
                in_array($status, [
                    'failed',
                    'rejected',
                    'cancelled',
                    'canceled',
                ], true)
            ) {

                return DB::transaction(
                    function () use ($refund, $response) {
                        $refund = PaymentRefund::query()
                            ->whereKey($refund->id)
                            ->lockForUpdate()
                            ->firstOrFail();

                        if (
                            $refund->status ===
                            RefundStatus::SUCCEEDED
                        ) {
                            return $refund;
                        }

                        $refund->failure_reason =
                            $response->message
                            ?? 'Refund rejected by provider.';

                        $refund->failure_code =
                            $response->status;

                        $refund->metadata = array_merge(
                            $refund->metadata ?? [],
                            [
                                'provider_response' =>
                                    $response->data,
                                'processed_via' =>
                                    'refund_api',
                            ]
                        );

                        $this->refundStateService->transition(
                            $refund,
                            RefundStatus::FAILED
                        );

                        $refund->save();

                        $refund->load('payment');

                        $this->syncPaymentState(
                            $refund->payment
                        );

                        return $refund->fresh([
                            'payment',
                            'attempt',
                            'provider',
                        ]);
                    }
                );
            }

            /*
             * Anything else is uncertain.
             */
            return $this->markUnknown(
                $refund,
                $response->message
                ?? 'Refund provider response was inconclusive.'
            );

        } catch (Throwable $e) {

            Log::error(
                'Payment refund provider request failed.',
                [
                    'refund_id' => $refund->id,
                    'refund_uuid' => $refund->refund_uuid,
                    'payment_id' => $refund->payment_id,
                    'provider_id' => $refund->payment_provider_id,
                    'exception' => get_class($e),
                ]
            );

            /*
             * We do NOT mark it FAILED.
             *
             * The provider may have processed the refund.
             */
            $refund = $this->markUnknown(
                $refund,
                'Refund request outcome is unknown and requires provider verification.'
            );

            /*
             * Verify later.
             *
             * This is NOT another refund request.
             */
            VerifyPaymentRefundJob::dispatch(
                $refund->id
            )
                ->onQueue('payments')
                ->delay(now()->addSeconds(30));

            return $refund;
        }
    }

    /**
     * Verify an UNKNOWN refund with the provider.
     *
     * This method is deliberately separate from processRefund()
     * so an uncertain refund can never be blindly submitted again.
     */
    public function verifyUnknownRefund(
        PaymentRefund $refund
    ): PaymentRefund {
        $refund = PaymentRefund::query()
            ->whereKey($refund->id)
            ->lockForUpdate()
            ->firstOrFail();

        /*
         * Only UNKNOWN refunds can enter provider verification.
         */
        if ($refund->status !== RefundStatus::UNKNOWN) {
            return $refund->fresh([
                'payment',
                'attempt',
                'provider',
            ]);
        }

        $refund->loadMissing([
            'payment',
            'attempt',
            'provider',
        ]);

        try {
            $gateway = $this->gatewayResolver->resolve(
                $refund->provider
            );

            $response = $gateway->verifyRefund($refund);

            /*
             * Provider confirms refund succeeded.
             */
            if (
                $response->success
                && $response->providerRefundId
            ) {
                return DB::transaction(function () use ($refund, $response) {
                    $lockedRefund = PaymentRefund::query()
                        ->whereKey($refund->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    /*
                     * Another worker may already have completed it.
                     */
                    if (
                        $lockedRefund->status ===
                        RefundStatus::SUCCEEDED
                    ) {
                        return $lockedRefund->fresh([
                            'payment',
                            'attempt',
                            'provider',
                        ]);
                    }

                    /*
                     * Do not allow an unrelated terminal state
                     * to be overwritten.
                     */
                    if (
                        $lockedRefund->status !==
                        RefundStatus::UNKNOWN
                    ) {
                        return $lockedRefund->fresh([
                            'payment',
                            'attempt',
                            'provider',
                        ]);
                    }

                    $lockedRefund->provider_refund_id =
                        $response->providerRefundId;

                    $lockedRefund->processed_at = now();

                    $lockedRefund->metadata = array_merge(
                        $lockedRefund->metadata ?? [],
                        [
                            'provider_response' =>
                                $response->data,

                            'processed_via' =>
                                'refund_verification',

                            'verified_at' =>
                                now()->toIso8601String(),
                        ]
                    );

                    $this->refundStateService->transition(
                        $lockedRefund,
                        RefundStatus::SUCCEEDED
                    );

                    $lockedRefund->save();

                    $this->syncPaymentState(
                        $lockedRefund->payment
                    );

                    return $lockedRefund->fresh([
                        'payment',
                        'attempt',
                        'provider',
                    ]);
                });
            }

            /*
             * Provider explicitly says the refund does not exist.
             *
             * This does NOT mean we immediately issue another refund.
             *
             * We move back to PENDING and let the normal recovery/
             * retry process decide what happens next.
             */
            if (
                strtolower((string) $response->status)
                === 'not_found'
            ) {
                return DB::transaction(function () use ($refund) {
                    $lockedRefund = PaymentRefund::query()
                        ->whereKey($refund->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (
                        $lockedRefund->status !==
                        RefundStatus::UNKNOWN
                    ) {
                        return $lockedRefund->fresh([
                            'payment',
                            'attempt',
                            'provider',
                        ]);
                    }

                    $this->refundStateService->transition(
                        $lockedRefund,
                        RefundStatus::PENDING
                    );

                    $lockedRefund->metadata = array_merge(
                        $lockedRefund->metadata ?? [],
                        [
                            'verification_status' => 'not_found',
                            'verification_at' =>
                                now()->toIso8601String(),
                        ]
                    );

                    $lockedRefund->save();

                    return $lockedRefund->fresh([
                        'payment',
                        'attempt',
                        'provider',
                    ]);
                });
            }

            /*
             * Provider still has an inconclusive answer.
             *
             * Keep UNKNOWN. The queue retry mechanism will
             * verify again.
             */
            return $refund->fresh([
                'payment',
                'attempt',
                'provider',
            ]);

        } catch (Throwable $e) {
            Log::warning(
                'Payment refund provider verification failed.',
                [
                    'refund_id' => $refund->id,
                    'refund_uuid' => $refund->refund_uuid,
                    'payment_id' => $refund->payment_id,
                    'provider_id' => $refund->payment_provider_id,
                    'exception' => get_class($e),
                ]
            );

            /*
             * IMPORTANT:
             * Do not change UNKNOWN to FAILED on a verification
             * network error.
             *
             * The provider may already have processed the refund.
             */
            throw $e;
        }
    }
    /**
     * Mark refund UNKNOWN after an uncertain external operation.
     */
    private function markUnknown(
        PaymentRefund $refund,
        string $reason
    ): PaymentRefund {
        return DB::transaction(function () use ($refund, $reason) {
            $refund = PaymentRefund::query()
                ->whereKey($refund->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Webhook may have completed the refund while
             * the API request was returning.
             */
            if ($refund->status === RefundStatus::SUCCEEDED) {
                return $refund;
            }

            if ($refund->status !== RefundStatus::UNKNOWN) {
                $this->refundStateService->transition(
                    $refund,
                    RefundStatus::UNKNOWN
                );
            }

            $refund->failure_reason = $reason;

            $refund->metadata = array_merge(
                $refund->metadata ?? [],
                [
                    'outcome' => 'unknown',
                    'unknown_at' => now()->toIso8601String(),
                ]
            );

            $refund->save();

            return $refund->fresh([
                'payment',
                'attempt',
                'provider',
            ]);
        });
    }

    /**
     * Synchronize aggregate payment refund state.
     *
     * This method is always called while the payment is locked.
     */
    private function syncPaymentState(
        Payment $payment
    ): Payment {
        $payment = Payment::query()
            ->whereKey($payment->id)
            ->lockForUpdate()
            ->firstOrFail();

        $successfulAmount = (int) $payment->refunds()
            ->where(
                'status',
                RefundStatus::SUCCEEDED->value
            )
            ->sum('amount');

        $pendingAmount = (int) $payment->refunds()
            ->whereIn('status', [
                RefundStatus::PENDING->value,
                RefundStatus::PROCESSING->value,
                RefundStatus::UNKNOWN->value,
            ])
            ->sum('amount');

        /*
         * Financial aggregate.
         */
        $payment->amount_refunded = $successfulAmount;

        /*
         * State.
         */
        if ($successfulAmount >= $payment->amount) {

            /*
             * Every unit of the payment has been refunded.
             */
            $payment->status = PaymentStatus::REFUNDED;

        } elseif ($pendingAmount > 0) {

            /*
             * One or more refunds are still unresolved.
             */
            $payment->status = PaymentStatus::REFUND_PENDING;

        } elseif ($successfulAmount > 0) {

            /*
             * At least one refund succeeded,
             * but the entire payment was not refunded.
             */
            $payment->status =
                PaymentStatus::PARTIALLY_REFUNDED;

        } else {

            /*
             * No successful/pending refund remains.
             */
            $payment->status = PaymentStatus::PAID;
        }

        /*
         * Validate before saving.
         */
        $this->invariantService->assertPayment(
            $payment
        );

        $payment->save();

        return $payment->refresh();
    }

    /**
     * Get provider ID from the latest usable payment attempt.
     */
    private function providerId(
        Payment $payment
    ): int {
        $attempt = PaymentAttempt::query()
            ->where('payment_id', $payment->id)
            ->whereNotNull('provider_payment_id')
            ->latest('id')
            ->first();

        if (!$attempt) {
            throw new RuntimeException(
                'No provider payment attempt is available.'
            );
        }

        return (int) $attempt->payment_provider_id;
    }
}