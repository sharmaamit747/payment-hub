<?php

namespace Tests\Support;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\DTOs\GatewayPaymentResponse;
use App\Payments\DTOs\GatewayRefundResponse;
use App\Payments\DTOs\GatewayVerificationResponse;
use App\Payments\DTOs\GatewayWebhookEvent;
use Illuminate\Http\Request;

class FakePaymentGateway implements PaymentGateway
{
    public ?GatewayPaymentResponse $createPaymentResponse = null;

    public ?GatewayVerificationResponse $verifyPaymentResponse = null;

    public ?GatewayVerificationResponse $paymentDetailsResponse = null;

    public ?GatewayRefundResponse $refundResponse = null;

    public ?GatewayRefundResponse $verifyRefundResponse = null;

    public bool $webhookValid = true;

    public ?GatewayWebhookEvent $webhookEvent = null;

    public function createPayment(
        Payment $payment,
        PaymentAttempt $attempt
    ): GatewayPaymentResponse {
        return $this->createPaymentResponse
            ?? new GatewayPaymentResponse(
                success: true,
                status: 'created',
                providerPaymentId: 'pay_fake_' . $payment->id,
                providerOrderId: 'order_fake_' . $payment->id,
                message: 'Fake payment created.',
                data: []
            );
    }

    public function verifyPayment(
        PaymentAttempt $attempt
    ): GatewayVerificationResponse {
        return $this->verifyPaymentResponse
            ?? new GatewayVerificationResponse(
                success: true,
                status: 'paid',
                providerPaymentId: $attempt->provider_payment_id,
                message: 'Fake payment verified.',
                data: []
            );
    }

    public function getPaymentDetails(
        PaymentAttempt $attempt
    ): GatewayVerificationResponse {
        return $this->paymentDetailsResponse
            ?? new GatewayVerificationResponse(
                success: true,
                status: 'paid',
                providerPaymentId: $attempt->provider_payment_id,
                message: 'Fake payment details.',
                data: []
            );
    }

    public function refund(
        Payment $payment,
        int $amount,
        string $idempotencyKey
    ): GatewayRefundResponse {
        return $this->refundResponse
            ?? new GatewayRefundResponse(
                success: true,
                status: 'succeeded',
                providerRefundId: 'rfnd_fake_' . $payment->id,
                message: 'Fake refund successful.',
                data: [
                    'amount' => $amount,
                    'idempotency_key' => $idempotencyKey,
                ]
            );
    }

    public function verifyRefund(
        PaymentRefund $refund
    ): GatewayRefundResponse {
        return $this->verifyRefundResponse
            ?? new GatewayRefundResponse(
                success: false,
                status: 'not_found',
                providerRefundId: null,
                message: 'Fake refund not found.',
                data: []
            );
    }

    public function verifyWebhook(Request $request): bool
    {
        return $this->webhookValid;
    }

    public function parseWebhook(
        Request $request
    ): GatewayWebhookEvent {
        if (!$this->webhookEvent) {
            throw new \RuntimeException(
                'Fake webhook event has not been configured.'
            );
        }

        return $this->webhookEvent;
    }
}