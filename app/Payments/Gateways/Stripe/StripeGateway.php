<?php

namespace App\Payments\Gateways\Stripe;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderCredential;
use App\Models\PaymentRefund;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\DTOs\GatewayPaymentResponse;
use App\Payments\DTOs\GatewayRefundResponse;
use App\Payments\DTOs\GatewayVerificationResponse;
use App\Payments\DTOs\GatewayWebhookEvent;
use App\Payments\Enums\PaymentEnvironment;
use App\Payments\Exceptions\GatewayAuthenticationException;
use App\Payments\Exceptions\GatewayException;
use App\Payments\Services\PaymentProviderCredentialService;
use Illuminate\Http\Request;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Webhook;
use Throwable;

class StripeGateway implements PaymentGateway
{
    private ?PaymentProviderCredential $credentials = null;

    private ?StripeClient $client = null;

    public function __construct(
        private PaymentProviderCredentialService $credentialService
    ) {
    }

    /**
     * Create Stripe PaymentIntent.
     */
    public function createPayment(
        Payment $payment,
        PaymentAttempt $attempt
    ): GatewayPaymentResponse {
        try {
            if ($payment->amount <= 0) {
                throw new GatewayException(
                    'Stripe payment amount must be greater than zero.'
                );
            }

            $credentials = $this->credentials($payment);
            $client = $this->client($credentials);

            $currency = strtolower($payment->currency);

            $intent = $client->paymentIntents->create([
                'amount' => $payment->amount,
                'currency' => $currency,
                'metadata' => [
                    'payment_uuid' => $payment->uuid,
                    'attempt_uuid' => $attempt->attempt_uuid,
                    'merchant_reference' => $payment->merchant_reference,
                    'order_reference' => $payment->order_reference,
                ],
                'description' => $payment->description,
            ], [
                'idempotency_key' => 'payment-attempt:' . $attempt->attempt_uuid,
            ]);

            if (!$intent->id) {
                throw new GatewayException(
                    'Stripe did not return a PaymentIntent ID.'
                );
            }

            return new GatewayPaymentResponse(
                success: true,
                status: $intent->status ?? 'created',
                providerPaymentId: $intent->id,
                providerOrderId: null,
                message: 'Stripe PaymentIntent created successfully.',
                data: $intent->toArray()
            );

        } catch (Throwable $e) {
            $this->throwGatewayException($e);
        }
    }

    /**
     * Verify Stripe PaymentIntent.
     */
    public function verifyPayment(
        PaymentAttempt $attempt
    ): GatewayVerificationResponse {
        try {
            $payment = $attempt->payment;

            if (!$attempt->provider_payment_id) {
                throw new GatewayException(
                    'Stripe PaymentIntent ID is missing for this attempt.'
                );
            }

            $credentials = $this->credentials($payment);
            $client = $this->client($credentials);

            $intent = $client->paymentIntents->retrieve(
                $attempt->provider_payment_id,
                []
            );

            $status = $intent->status ?? 'unknown';

            return new GatewayVerificationResponse(
                success: $status === 'succeeded',
                status: $status,
                providerPaymentId: $intent->id ?? null,
                message: 'Stripe PaymentIntent verification completed.',
                data: $intent->toArray()
            );

        } catch (Throwable $e) {
            $this->throwGatewayException($e);
        }
    }

    /**
     * Create Stripe refund.
     */
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

            if (!$attempt) {
                throw new GatewayException(
                    'No Stripe PaymentIntent found for refund.'
                );
            }

            $credentials = $this->credentials($payment);
            $client = $this->client($credentials);

            $refund = $client->refunds->create([
                'payment_intent' => $attempt->provider_payment_id,
                'amount' => $amount,
                'metadata' => [
                    'payment_uuid' => $payment->uuid,
                    'idempotency_key' => $idempotencyKey,
                ],
            ], [
                'idempotency_key' => $idempotencyKey,
            ]);

            return new GatewayRefundResponse(
                success: in_array(
                    $refund->status,
                    ['succeeded', 'pending'],
                    true
                ),
                status: $refund->status ?? 'unknown',
                providerRefundId: $refund->id ?? null,
                message: 'Stripe refund created successfully.',
                data: $refund->toArray()
            );

        } catch (Throwable $e) {
            $this->throwGatewayException($e);
        }
    }

    /**
     * Verify Stripe webhook signature.
     */
    public function verifyWebhook(Request $request): bool
    {
        try {
            $provider = $this->provider();

            $environment = PaymentEnvironment::from(
                config(
                    'payments.environment',
                    PaymentEnvironment::TEST->value
                )
            );

            $credentials = $this->credentialService
                ->getActiveCredentials(
                    $provider,
                    $environment
                );

            if (!$credentials->webhook_secret) {
                return false;
            }

            $signature = $request->header('Stripe-Signature');

            if (!$signature) {
                return false;
            }

            Webhook::constructEvent(
                $request->getContent(),
                $signature,
                $credentials->webhook_secret
            );

            return true;

        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Parse Stripe webhook.
     */
    public function parseWebhook(
        Request $request
    ): GatewayWebhookEvent {
        $payload = $request->json()->all();

        $eventId = $payload['id'] ?? null;

        $eventType = $payload['type'] ?? 'unknown';

        $object = $payload['data']['object'] ?? [];

        $providerPaymentId = null;
        $providerRefundId = null;
        $providerOrderId = null;
        $amount = null;
        $currency = null;
        $status = 'unknown';

        if (
            str_starts_with($eventType, 'payment_intent.')
        ) {
            $providerPaymentId = $object['id'] ?? null;

            $amount = $object['amount_received']
                ?? $object['amount']
                ?? null;

            $currency = $object['currency'] ?? null;

            $status = $object['status'] ?? 'unknown';

            $providerOrderId =
                $object['metadata']['order_reference']
                ?? null;
        }

        if (
            str_starts_with($eventType, 'charge.')
        ) {
            $providerPaymentId =
                $object['payment_intent'] ?? null;

            $amount =
                $object['amount_captured']
                ?? $object['amount']
                ?? null;

            $currency = $object['currency'] ?? null;

            $status = $object['status'] ?? 'unknown';
        }

        if (
            str_starts_with($eventType, 'refund.')
        ) {
            $providerRefundId = $object['id'] ?? null;

            $amount = $object['amount'] ?? null;

            $currency = $object['currency'] ?? null;

            $status = $object['status'] ?? 'unknown';

            $providerPaymentId =
                $object['payment_intent'] ?? null;
        }

        return new GatewayWebhookEvent(
            eventId: $eventId ?? '',
            eventType: $eventType,
            providerPaymentId: $providerPaymentId,
            providerOrderId: $providerOrderId,
            providerTransactionId: $object['charge'] ?? null,
            providerRefundId: $providerRefundId,
            status: $status,
            amount: $amount,
            currency: $currency,
            data: $payload
        );
    }

    /**
     * Retrieve PaymentIntent details.
     */
    public function getPaymentDetails(
        PaymentAttempt $attempt
    ): GatewayVerificationResponse {
        return $this->verifyPayment($attempt);
    }

    /**
     * Retrieve refund details.
     */
    public function verifyRefund(
        PaymentRefund $refund
    ): GatewayRefundResponse {
        try {
            if (!$refund->provider_refund_id) {
                throw new GatewayException(
                    'Stripe refund ID is missing.'
                );
            }

            $payment = $refund->payment;

            $credentials = $this->credentials($payment);
            $client = $this->client($credentials);

            $stripeRefund = $client->refunds->retrieve(
                $refund->provider_refund_id,
                []
            );

            return new GatewayRefundResponse(
                success: $stripeRefund->status === 'succeeded',
                status: $stripeRefund->status ?? 'unknown',
                providerRefundId: $stripeRefund->id ?? null,
                message: 'Stripe refund verification completed.',
                data: $stripeRefund->toArray()
            );

        } catch (Throwable $e) {
            $this->throwGatewayException($e);
        }
    }

    private function credentials(
        Payment $payment
    ): PaymentProviderCredential {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $provider = $this->provider();

        $environment = PaymentEnvironment::from(
            config(
                'payments.environment',
                PaymentEnvironment::TEST->value
            )
        );

        return $this->credentials =
            $this->credentialService->getActiveCredentials(
                $provider,
                $environment
            );
    }

    private function client(
        PaymentProviderCredential $credentials
    ): StripeClient {
        if ($this->client !== null) {
            return $this->client;
        }

        if (!$credentials->secret_key) {
            throw new GatewayAuthenticationException(
                'Stripe secret key is missing.'
            );
        }

        return $this->client = new StripeClient(
            $credentials->secret_key
        );
    }

    private function provider()
    {
        $provider = \App\Models\PaymentProvider::where(
            'code',
            'stripe'
        )->first();

        if (!$provider) {
            throw new GatewayException(
                'Stripe payment provider is not configured.'
            );
        }

        return $provider;
    }

    private function throwGatewayException(
        Throwable $e
    ): never {
        if (
            $e instanceof GatewayException
            || $e instanceof GatewayAuthenticationException
        ) {
            throw $e;
        }

        if ($e instanceof ApiConnectionException) {
            throw new \App\Payments\Exceptions\GatewayTimeoutException(
                'Stripe API connection failed: ' . $e->getMessage(),
                previous: $e
            );
        }

        if ($e instanceof ApiErrorException) {
            throw new GatewayException(
                'Stripe API error: ' . $e->getMessage(),
                previous: $e
            );
        }

        throw new GatewayException(
            'Stripe gateway error: ' . $e->getMessage(),
            previous: $e
        );
    }
}