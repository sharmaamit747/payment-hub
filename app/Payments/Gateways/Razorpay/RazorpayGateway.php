<?php

namespace App\Payments\Gateways\Razorpay;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Models\PaymentProviderCredential;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\DTOs\GatewayPaymentResponse;
use App\Payments\DTOs\GatewayRefundResponse;
use App\Payments\DTOs\GatewayVerificationResponse;
use App\Payments\DTOs\GatewayWebhookEvent;
use App\Payments\Enums\PaymentEnvironment;
use App\Payments\Exceptions\GatewayAuthenticationException;
use App\Payments\Exceptions\GatewayException;
use App\Payments\Exceptions\GatewayTimeoutException;
use App\Payments\Services\PaymentProviderCredentialService;
use Illuminate\Http\Request;
use Razorpay\Api\Api;
use Throwable;

class RazorpayGateway implements PaymentGateway
{
    private ?PaymentProviderCredential $credentials = null;

    private ?Api $client = null;

    public function __construct(
        private PaymentProviderCredentialService $credentialService
    ) {
    }

    public function createPayment(
        Payment $payment,
        PaymentAttempt $attempt
    ): GatewayPaymentResponse {
        try {
            $credentials = $this->credentials($payment);

            $api = $this->client($credentials);

            /*
             * Razorpay amount is in the smallest currency unit.
             *
             * Example:
             * ₹500.00 = 50000 paise
             */
            $order = $api->order->create([
                'amount' => $payment->amount,
                'currency' => strtoupper($payment->currency),
                'receipt' => $payment->merchant_reference,
                'notes' => [
                    'payment_uuid' => $payment->uuid,
                    'attempt_uuid' => $attempt->attempt_uuid,
                ],
            ]);

            if (
                !isset($order['id']) ||
                ($order['amount'] ?? null) !== $payment->amount ||
                strtoupper($order['currency'] ?? '') !== strtoupper($payment->currency)
            ) {
                throw new GatewayException(
                    'Razorpay returned an invalid order response.'
                );
            }

            return new GatewayPaymentResponse(
                success: true,
                status: 'created',
                providerPaymentId: null,
                providerOrderId: $order['id'] ?? null,
                message: 'Razorpay order created successfully.',
                data: $order->toArray()
            );

        } catch (Throwable $e) {
            $this->throwGatewayException($e);
        }
    }

    public function verifyPayment(
        PaymentAttempt $attempt
    ): GatewayVerificationResponse {
        try {
            $payment = $attempt->payment;

            $credentials = $this->credentials($payment);

            $api = $this->client($credentials);

            if (!$attempt->provider_payment_id) {
                throw new GatewayException(
                    'Razorpay payment ID is missing for this attempt.'
                );
            }

            $razorpayPayment = $api->payment->fetch(
                $attempt->provider_payment_id
            );

            $status = $razorpayPayment['status'] ?? 'unknown';

            return new GatewayVerificationResponse(
                success: $status === 'captured',
                status: $status,
                providerPaymentId: $razorpayPayment['id'] ?? null,
                message: 'Razorpay payment verification completed.',
                data: $razorpayPayment->toArray()
            );

        } catch (Throwable $e) {
            $this->throwGatewayException($e);
        }
    }

    public function refund(
        Payment $payment,
        int $amount,
        string $idempotencyKey
    ): GatewayRefundResponse {
        try {
            $attempt = $payment->attempts()
                ->whereNotNull('provider_payment_id')
                ->latest('id')
                ->first();

            if ($attempt === null) {
                throw new GatewayException(
                    'No Razorpay payment attempt found for refund.'
                );
            }

            if (!$attempt->provider_payment_id) {
                throw new GatewayException(
                    'Razorpay payment ID is missing for refund.'
                );
            }

            $credentials = $this->credentials($payment);

            if ($payment->amount <= 0) {
                throw new GatewayException(
                    'Razorpay payment amount must be greater than zero.'
                );
            }

            if (strtoupper($payment->currency) !== 'INR') {
                throw new GatewayException(
                    'Razorpay integration currently supports INR only.'
                );
            }

            $api = $this->client($credentials);

            /*
             * Razorpay refund amount is in the smallest currency unit.
             */
            $refund = $api->payment
                ->fetch($attempt->provider_payment_id)
                ->refund([
                    'amount' => $amount,
                    'notes' => [
                        'payment_uuid' => $payment->uuid,
                        'idempotency_key' => $idempotencyKey,
                    ],
                ]);

            return new GatewayRefundResponse(
                success: true,
                status: $refund['status'] ?? 'processed',
                providerRefundId: $refund['id'] ?? null,
                message: 'Razorpay refund created successfully.',
                data: $refund->toArray()
            );

        } catch (Throwable $e) {
            $this->throwGatewayException($e);
        }
    }

    public function verifyWebhook(Request $request): bool
    {
        $provider = $this->provider();

        $environment = PaymentEnvironment::from(
            config('payments.environment', PaymentEnvironment::TEST->value)
        );

        $credentials = $this->credentialService->getActiveCredentials(
            $provider,
            $environment
        );

        $signature = $request->header('X-Razorpay-Signature');

        if (!$signature || !$credentials->webhook_secret) {
            return false;
        }

        $expected = hash_hmac(
            'sha256',
            $request->getContent(),
            $credentials->webhook_secret
        );

        return hash_equals($expected, $signature);
    }

    public function parseWebhook(
        Request $request
    ): GatewayWebhookEvent {
        $payload = $request->json()->all();

        $eventId =
            $request->header('X-Razorpay-Event-Id')
            ?? ($payload['id'] ?? null);

        $eventType = $payload['event'] ?? 'unknown';

        $paymentEntity =
            $payload['payload']['payment']['entity']
            ?? [];

        $orderEntity =
            $payload['payload']['order']['entity']
            ?? [];

        $refundEntity =
            $payload['payload']['refund']['entity']
            ?? [];

        return new GatewayWebhookEvent(
            eventId: $eventId ?? '',
            eventType: $eventType,
            providerPaymentId: $paymentEntity['id'] ?? null,
            providerOrderId: $orderEntity['id']
            ?? ($paymentEntity['order_id'] ?? null),
            providerTransactionId: null,
            providerRefundId: $refundEntity['id'] ?? null,
            status: $paymentEntity['status']
            ?? ($refundEntity['status'] ?? 'unknown'),
            amount: $paymentEntity['amount']
            ?? ($refundEntity['amount'] ?? null),
            currency: $paymentEntity['currency']
            ?? ($refundEntity['currency'] ?? null),
            data: $payload
        );
    }

    private function credentials(Payment $payment): PaymentProviderCredential
    {
        $provider = $this->provider();

        $environment = PaymentEnvironment::from(
            config('payments.environment', PaymentEnvironment::TEST->value)
        );

        return $this->credentialService->getActiveCredentials(
            $provider,
            $environment
        );
    }

    private function client(
        PaymentProviderCredential $credentials
    ): Api {
        if ($this->client !== null) {
            return $this->client;
        }

        if (
            !$credentials->public_key ||
            !$credentials->secret_key
        ) {
            throw new GatewayAuthenticationException(
                'Razorpay API credentials are incomplete.'
            );
        }

        return $this->client = new Api(
            $credentials->public_key,
            $credentials->secret_key
        );
    }

    private function provider()
    {
        $provider = \App\Models\PaymentProvider::where(
            'code',
            'razorpay'
        )->first();

        if (!$provider) {
            throw new GatewayException(
                'Razorpay payment provider is not configured.'
            );
        }

        return $provider;
    }

    private function throwGatewayException(
        Throwable $e
    ): never {
        if (
            $e instanceof GatewayException
        ) {
            throw $e;
        }

        $message = $e->getMessage();

        if (
            str_contains(
                strtolower($message),
                'timeout'
            )
        ) {
            throw new GatewayTimeoutException(
                'Razorpay request timed out.',
                0,
                $e
            );
        }

        if (
            str_contains(
                strtolower($message),
                'authentication'
            ) ||
            str_contains(
                strtolower($message),
                'unauthorized'
            ) ||
            str_contains(
                strtolower($message),
                'invalid api key'
            )
        ) {
            throw new GatewayAuthenticationException(
                'Razorpay authentication failed.',
                0,
                $e
            );
        }

        throw new GatewayException(
            'Razorpay request failed: ' . $message,
            0,
            $e
        );
    }

    public function getPaymentDetails(
        PaymentAttempt $attempt
    ): GatewayVerificationResponse {
        try {
            if (!$attempt->provider_payment_id) {
                throw new GatewayException(
                    'Provider payment ID is missing.'
                );
            }

            $payment = $attempt->payment;

            if (!$payment) {
                throw new GatewayException(
                    'Payment relation is missing for attempt.'
                );
            }

            $credentials = $this->credentials($payment);
            $api = $this->client($credentials);

            $razorpayPayment = $api->payment->fetch(
                $attempt->provider_payment_id
            );

            $status = strtolower(
                (string) ($razorpayPayment['status'] ?? 'unknown')
            );

            return new GatewayVerificationResponse(
                success: $status === 'captured',
                status: $status,
                providerPaymentId:
                $razorpayPayment['id'] ?? null,
                message: null,
                data: [
                    'id' =>
                        $razorpayPayment['id'] ?? null,

                    'order_id' =>
                        $razorpayPayment['order_id'] ?? null,

                    'amount' =>
                        (int) ($razorpayPayment['amount'] ?? 0),

                    'currency' =>
                        $razorpayPayment['currency'] ?? null,

                    'status' =>
                        $status,

                    'method' =>
                        $razorpayPayment['method'] ?? null,

                    'captured' =>
                        $razorpayPayment['captured'] ?? null,
                ],
            );

        } catch (Throwable $e) {
            $this->throwGatewayException($e);
        }
    }

    public function verifyRefund(
        PaymentRefund $refund
    ): GatewayRefundResponse {
        try {
            $attempt = $refund->attempt;

            if (!$attempt?->provider_payment_id) {
                throw new GatewayException(
                    'Provider payment ID is missing for refund verification.'
                );
            }

            $payment = $refund->payment;

            if (!$payment) {
                throw new GatewayException(
                    'Payment relation is missing for refund verification.'
                );
            }

            $credentials = $this->credentials($payment);
            $api = $this->client($credentials);

            /*
             * If we already know the provider refund ID,
             * fetch that exact refund.
             */
            if ($refund->provider_refund_id) {
                $providerRefund = $api->payment
                    ->fetch($attempt->provider_payment_id)
                    ->fetchRefund($refund->provider_refund_id);

                $providerAmount = (int) (
                    $providerRefund['amount'] ?? 0
                );

                $providerCurrency = strtoupper(
                    (string) (
                        $providerRefund['currency'] ?? ''
                    )
                );

                if (
                    $providerAmount !== (int) $refund->amount
                    || $providerCurrency !== strtoupper(
                        (string) $refund->currency
                    )
                ) {
                    throw new GatewayException(
                        'Provider refund amount or currency does not match local refund.'
                    );
                }

                return new GatewayRefundResponse(
                    success: true,
                    status: strtolower(
                        (string) (
                            $providerRefund['status']
                            ?? 'unknown'
                        )
                    ),
                    providerRefundId:
                    $providerRefund['id'] ?? null,
                    message: null,
                    data: $providerRefund->toArray(),
                );
            }

            /*
             * Provider refund ID is unknown.
             *
             * Retrieve refunds belonging to this provider payment.
             */
            $refunds = $api->payment
                ->fetch($attempt->provider_payment_id)
                ->fetchMultipleRefund([
                    'count' => 100,
                ]);

            foreach ($refunds->items as $providerRefund) {
                $providerAmount = (int) (
                    $providerRefund['amount'] ?? 0
                );

                $providerCurrency = strtoupper(
                    (string) (
                        $providerRefund['currency'] ?? ''
                    )
                );

                if (
                    $providerAmount !== (int) $refund->amount
                    || $providerCurrency !== strtoupper(
                        (string) $refund->currency
                    )
                ) {
                    continue;
                }

                /*
                 * Match our idempotency key if it was stored
                 * in Razorpay notes.
                 */
                $notes = $providerRefund['notes'] ?? [];

                $providerIdempotencyKey =
                    $notes['idempotency_key'] ?? null;

                if (
                    $providerIdempotencyKey !== null
                    && $providerIdempotencyKey ===
                    $refund->idempotency_key
                ) {
                    return new GatewayRefundResponse(
                        success: true,
                        status: strtolower(
                            (string) (
                                $providerRefund['status']
                                ?? 'unknown'
                            )
                        ),
                        providerRefundId:
                        $providerRefund['id'] ?? null,
                        message: null,
                        data: $providerRefund->toArray(),
                    );
                }
            }

            /*
             * Do not guess based on amount alone.
             */
            return new GatewayRefundResponse(
                success: false,
                status: 'not_found',
                providerRefundId: null,
                message:
                'Matching Razorpay refund was not found.',
                data: [],
            );

        } catch (Throwable $e) {
            $this->throwGatewayException($e);
        }
    }
}