<?php

namespace App\Payments\Contracts;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Payments\DTOs\GatewayPaymentResponse;
use App\Payments\DTOs\GatewayRefundResponse;
use App\Payments\DTOs\GatewayVerificationResponse;
use App\Payments\DTOs\GatewayWebhookEvent;
use Illuminate\Http\Request;
use App\Models\PaymentRefund;

interface PaymentGateway
{
    /**
     * Create/initiate a payment with the provider.
     */
    public function createPayment(
        Payment $payment,
        PaymentAttempt $attempt
    ): GatewayPaymentResponse;

    /**
     * Verify the current payment status with the provider.
     */
    public function verifyPayment(
        PaymentAttempt $attempt
    ): GatewayVerificationResponse;

    /**
     * Refund a payment.
     */
    public function refund(
        Payment $payment,
        int $amount,
        string $idempotencyKey
    ): GatewayRefundResponse;

    /**
     * Verify webhook signature.
     */
    public function verifyWebhook(
        Request $request
    ): bool;

    /**
     * Parse provider webhook into a normalized internal structure.
     *
     * We will create a dedicated DTO for this later.
     */
    public function parseWebhook(
        Request $request
    ): GatewayWebhookEvent;

    public function getPaymentDetails(
        PaymentAttempt $attempt
    ): GatewayVerificationResponse;

    public function verifyRefund(
        PaymentRefund $refund
    ): GatewayRefundResponse;
}