<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateRefundRequest;
use App\Models\Payment;
use App\Payments\Services\PaymentRefundService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class PaymentRefundController extends Controller
{
    public function store(
        CreateRefundRequest $request,
        Payment $payment,
        PaymentRefundService $service
    ): JsonResponse {
        try {
            $refund = $service->createRefund(
                payment: $payment,
                amount: (int) $request->integer('amount'),
                idempotencyKey: $request->string('idempotency_key')->toString(),
                reason: $request->input('reason'),
                metadata: $request->input('metadata', [])
            );

            return response()->json([
                'success' => true,
                'message' => 'Refund request accepted.',
                'data' => [
                    'refund' => [
                        'refund_uuid' => $refund->refund_uuid,
                        'amount' => $refund->amount,
                        'currency' => $refund->currency,
                        'status' => $refund->status->value,
                        'provider_refund_id' => $refund->provider_refund_id,
                        'reason' => $refund->reason,
                        'created_at' => $refund->created_at,
                    ],
                ],
            ], 202);

        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}