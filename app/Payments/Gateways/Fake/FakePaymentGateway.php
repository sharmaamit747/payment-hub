<?php

namespace App\Payments\Gateways\Fake;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentProvider;
use App\Models\PaymentProviderCredential;
use App\Models\PaymentRefund;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\DTOs\GatewayPaymentResponse;
use App\Payments\DTOs\GatewayRefundResponse;
use App\Payments\DTOs\GatewayVerificationResponse;
use App\Payments\DTOs\GatewayWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FakePaymentGateway implements PaymentGateway
{
    public function createPayment(
        Payment $payment,
        PaymentAttempt $attempt
    ): GatewayPaymentResponse {
        $providerOrderId = 'fake_order_' . Str::lower(Str::random(20));

        return new GatewayPaymentResponse(
            success: true,
            status: 'created',
            providerPaymentId: null,
            providerOrderId: $providerOrderId,
            message: 'Fake payment order created.',
            data: [
                'id' => $providerOrderId,
                'entity' => 'order',
                'amount' => $payment->amount,
                'currency' => strtoupper($payment->currency),
                'status' => 'created',
                'receipt' => $payment->merchant_reference,
                'fake' => true,
            ],
        );
    }

    public function verifyPayment(
        PaymentAttempt $attempt
    ): GatewayVerificationResponse {

        $payment = $attempt->payment;

        $providerPaymentId =
            $attempt->provider_payment_id
            ?? 'fake_payment_' . Str::lower(Str::random(20));

        return new GatewayVerificationResponse(
            success: true,

            status: 'captured',

            providerPaymentId:
            $providerPaymentId,

            message:
            'Fake payment verified.',

            data: [

                'fake' => true,

                'id' =>
                    $providerPaymentId,

                'status' =>
                    'captured',

                'amount' =>
                    (int) $payment->amount,

                'currency' =>
                    strtoupper(
                        (string) $payment->currency
                    ),

                'order_id' =>
                    $attempt->provider_order_id,

            ],
        );
    }

    public function refund(
        Payment $payment,
        int $amount,
        string $idempotencyKey
    ): GatewayRefundResponse {
        return new GatewayRefundResponse(
            success: true,
            status: 'processed',
            providerRefundId: 'fake_refund_' . Str::lower(Str::random(20)),
            message: 'Fake refund processed.',
            data: [
                'amount' => $amount,
                'currency' => strtoupper($payment->currency),
                'idempotency_key' => $idempotencyKey,
                'fake' => true,
            ],
        );
    }

    public function verifyWebhook(Request $request): bool
    {
        return true;
    }

    public function parseWebhook(Request $request): GatewayWebhookEvent
    {
        $payload = $request->json()->all();

        return new GatewayWebhookEvent(
            eventId: (string) (
                $request->header('X-Fake-Event-Id')
                ?? ($payload['id'] ?? '')
            ),

            eventType: (string) (
                $payload['event'] ?? 'payment.captured'
            ),

            providerPaymentId: data_get(
                $payload,
                'payload.payment.entity.id'
            ),

            providerOrderId: data_get(
                $payload,
                'payload.payment.entity.order_id'
            ),

            providerTransactionId: data_get(
                $payload,
                'payload.payment.entity.id'
            ),

            providerRefundId: data_get(
                $payload,
                'payload.refund.entity.id'
            ),

            status: data_get(
                $payload,
                'payload.payment.entity.status'
            ),

            amount: data_get(
                $payload,
                'payload.payment.entity.amount'
            ) ?? data_get(
                $payload,
                'payload.refund.entity.amount'
            ),

            currency: data_get(
                $payload,
                'payload.payment.entity.currency'
            ) ?? data_get(
                $payload,
                'payload.refund.entity.currency'
            ),

            data: $payload,
        );
    }

    public function getPaymentDetails(
        PaymentAttempt $attempt
    ): GatewayVerificationResponse {
        return $this->verifyPayment($attempt);
    }

    public function verifyRefund(
        PaymentRefund $refund
    ): GatewayRefundResponse {
        return new GatewayRefundResponse(
            success: true,
            status: 'processed',
            providerRefundId: $refund->provider_refund_id
            ?? 'fake_refund_' . Str::lower(Str::random(20)),
            message: 'Fake refund verified.',
            data: [
                'fake' => true,
                'status' => 'processed',
                'amount' => $refund->amount,
                'currency' => strtoupper($refund->currency),
            ],
        );
    }
}