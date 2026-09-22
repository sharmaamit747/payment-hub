<?php

namespace App\Payments\Gateways;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\DTOs\GatewayPaymentResponse;
use App\Payments\DTOs\GatewayRefundResponse;
use App\Payments\DTOs\GatewayVerificationResponse;
use App\Payments\DTOs\GatewayWebhookEvent;
use Illuminate\Http\Request;

class TestGateway implements PaymentGateway
{
    public function createPayment(
        Payment $payment,
        PaymentAttempt $attempt
    ): GatewayPaymentResponse {
        throw new \RuntimeException('TestGateway createPayment not implemented.');
    }

    public function verifyPayment(
        PaymentAttempt $attempt
    ): GatewayVerificationResponse {
        return new GatewayVerificationResponse(
            success: true,
            status: 'paid',
            providerPaymentId: $attempt->provider_payment_id,
            message: 'Test payment verified.',
            data: [
                'amount' => $attempt->amount,
                'currency' => $attempt->payment->currency,
            ],
        );
    }

    public function refund(
        Payment $payment,
        int $amount,
        string $idempotencyKey
    ): GatewayRefundResponse {
        throw new \RuntimeException('TestGateway refund not implemented.');
    }

    public function verifyWebhook(
        Request $request
    ): bool {
        return true;
    }

    public function parseWebhook(
        Request $request
    ): GatewayWebhookEvent {
        throw new \RuntimeException('TestGateway parseWebhook not implemented.');
    }

    public function getPaymentDetails(
        PaymentAttempt $attempt
    ): GatewayVerificationResponse {
        return $this->verifyPayment($attempt);
    }

    public function verifyRefund(
        PaymentRefund $refund
    ): GatewayRefundResponse {
        throw new \RuntimeException('TestGateway verifyRefund not implemented.');
    }
}