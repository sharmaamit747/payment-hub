<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Payments\Services\PaymentVerificationService;
use Illuminate\Http\JsonResponse;

class PaymentVerificationController extends Controller
{
    public function store(
        PaymentAttempt $attempt,
        PaymentVerificationService $service
    ): JsonResponse {
        $attempt = $service->verify($attempt);

        return response()->json([
            'success' => true,
            'data' => [
                'attempt' => [
                    'attempt_uuid' => $attempt->attempt_uuid,
                    'provider' => $attempt->provider->code,
                    'provider_order_id' =>
                        $attempt->provider_order_id,
                    'provider_payment_id' =>
                        $attempt->provider_payment_id,
                    'status' => $attempt->status->value,
                    'failure_code' =>
                        $attempt->failure_code,
                    'failure_reason' =>
                        $attempt->failure_reason,
                ],
                'payment' => [
                    'uuid' => $attempt->payment->uuid,
                    'status' => $attempt->payment->status->value,
                ],
            ],
        ]);
    }
}