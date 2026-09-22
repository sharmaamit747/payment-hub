<?php

namespace Tests\Feature\Payments\Support;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\DTOs\GatewayPaymentResponse;
use App\Payments\DTOs\GatewayRefundResponse;
use App\Payments\DTOs\GatewayVerificationResponse;
use App\Payments\DTOs\GatewayWebhookEvent;
use Illuminate\Http\Request;

class TestRefundGateway implements PaymentGateway
{
    public function createPayment(
        Payment $payment,
        PaymentAttempt $attempt
    ): GatewayPaymentResponse {
        throw new \RuntimeException('Not used.');
    }

    public function verifyPayment(
        PaymentAttempt $attempt
    ): GatewayVerificationResponse {
        throw new \RuntimeException('Not used.');
    }

    public function refund(
        Payment $payment,
        int $amount,
        string $idempotencyKey
    ): GatewayRefundResponse {
        throw new \RuntimeException('Not used.');
    }

    public function verifyWebhook(
        Request $request
    ): bool {
        return true;
    }

    public function parseWebhook(
        Request $request
    ): GatewayWebhookEvent {
        throw new \RuntimeException('Not used.');
    }

    public function getPaymentDetails(
        PaymentAttempt $attempt
    ): GatewayVerificationResponse {
        throw new \RuntimeException('Not used.');
    }

    public function verifyRefund(
        PaymentRefund $refund
    ): GatewayRefundResponse {
        return new GatewayRefundResponse(
            success: true,
            status: 'succeeded',
            providerRefundId: 'refund_provider_001',
            message: 'Refund confirmed.',
            data: [
                'amount' => 20000,
                'currency' => 'INR',
            ],
        );
    }
}