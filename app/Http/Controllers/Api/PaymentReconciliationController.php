<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Payments\Jobs\ReconcilePaymentAttemptJob;
use App\Models\PaymentReconciliation;
use App\Payments\Enums\ReconciliationResolutionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentReconciliationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PaymentReconciliation::query()
            ->with([
                'provider:id,code,name',
                'payment:id,uuid,merchant_reference,status,amount,currency',
                'attempt:id,attempt_uuid,status',
            ])
            ->latest('id');

        if ($request->filled('provider')) {
            $query->whereHas('provider', function ($q) use ($request) {
                $q->where(
                    'code',
                    strtolower($request->string('provider'))
                );
            });
        }

        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->string('status')
            );
        }

        if ($request->filled('resolution_status')) {
            $query->where(
                'resolution_status',
                $request->string('resolution_status')
            );
        }

        if ($request->filled('mismatch_type')) {
            $query->where(
                'mismatch_type',
                $request->string('mismatch_type')
            );
        }

        if ($request->filled('merchant_reference')) {
            $query->whereHas('payment', function ($q) use ($request) {
                $q->where(
                    'merchant_reference',
                    $request->string('merchant_reference')
                );
            });
        }

        if ($request->filled('from')) {
            $query->where(
                'created_at',
                '>=',
                $request->date('from')
            );
        }

        if ($request->filled('to')) {
            $query->where(
                'created_at',
                '<=',
                $request->date('to')->endOfDay()
            );
        }

        $perPage = min(
            100,
            max(
                1,
                (int) $request->input('per_page', 25)
            )
        );

        $results = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }

    public function show(
        PaymentReconciliation $reconciliation
    ): JsonResponse {
        $reconciliation->load([
            'provider',
            'payment',
            'attempt',
        ]);

        return response()->json([
            'success' => true,
            'data' => $reconciliation,
        ]);
    }

    public function retry(
        PaymentReconciliation $reconciliation
    ): JsonResponse {
        if (!$reconciliation->payment_attempt_id) {
            return response()->json([
                'success' => false,
                'message' => 'Reconciliation has no payment attempt.',
            ], 422);
        }

        if (
            $reconciliation->resolution_status ===
            ReconciliationResolutionStatus::RESOLVED
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Reconciliation is already resolved.',
            ], 409);
        }

        ReconcilePaymentAttemptJob::dispatch(
            $reconciliation->payment_attempt_id
        )
            ->onQueue('payments')
            ->afterCommit();

        $reconciliation->update([
            'resolution_status' =>
                ReconciliationResolutionStatus::IN_PROGRESS,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Reconciliation retry queued.',
        ], 202);
    }

    public function resolve(
        Request $request,
        PaymentReconciliation $reconciliation
    ): JsonResponse {
        if (
            $reconciliation->resolution_status ===
            ReconciliationResolutionStatus::RESOLVED
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Reconciliation is already resolved.',
            ], 409);
        }

        $validated = $request->validate([
            'note' => [
                'required',
                'string',
                'min:5',
                'max:1000',
            ],
        ]);

        $reconciliation->update([
            'resolution_status' =>
                ReconciliationResolutionStatus::RESOLVED,

            'resolution_note' => $validated['note'],

            'resolved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Reconciliation marked as resolved.',
            'data' => $reconciliation->fresh(),
        ]);
    }
}