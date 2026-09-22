<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePaymentRequest;
use App\Payments\Services\PaymentCreationService;
use Illuminate\Http\JsonResponse;
use App\Payments\Exceptions\IdempotencyConflictException;

class PaymentController extends Controller
{
    public function store(
        CreatePaymentRequest $request,
        PaymentCreationService $service
    ): JsonResponse {
        try {
            $result = $service->create(
                $request->validated(),
                $request->validated('idempotency_key')
            );
        } catch (IdempotencyConflictException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        }

        return response()->json(
            [
                'success' => true,
                'data' => [
                    'payment' => [
                        'uuid' => $result['payment']->uuid,
                        'merchant_reference' => $result['payment']->merchant_reference,
                        'amount' => $result['payment']->amount,
                        'currency' => $result['payment']->currency,
                        'status' => $result['payment']->status->value,
                        'payment_method' => $result['payment']->payment_method,
                        'attempt' => $result['payment']->attempts->first()
                            ? [
                                'attempt_uuid' => $result['payment']->attempts->first()->attempt_uuid,
                                'provider' => $result['payment']->attempts->first()->provider->code,
                                'status' => $result['payment']->attempts->first()->status->value,
                            ]
                            : null,
                    ],
                    'replayed' => $result['replayed'],
                ],
            ],
            $result['response_status']
        );
    }
}