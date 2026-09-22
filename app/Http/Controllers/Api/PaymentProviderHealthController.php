<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentProvider;
use App\Payments\Services\PaymentProviderCircuitBreaker;
use Illuminate\Http\JsonResponse;

class PaymentProviderHealthController extends Controller
{
    public function index(
        PaymentProviderCircuitBreaker $breaker
    ): JsonResponse {
        $providers = PaymentProvider::query()
            ->where('is_active', true)
            ->orderBy('priority')
            ->get();

        $data = $providers->map(function (PaymentProvider $provider) use ($breaker) {
            $health = $breaker->getHealth($provider);

            return [
                'provider' => $provider->code,
                'name' => $provider->name,
                'state' => $health->state,
                'available' => $breaker->isAvailable($provider),
                'consecutive_failures' => $health->consecutive_failures,
                'failure_threshold' => $health->failure_threshold,
                'recovery_timeout_seconds' =>
                    $health->recovery_timeout_seconds,
                'opened_at' => $health->opened_at,
                'next_retry_at' => $health->next_retry_at,
                'last_failure_at' => $health->last_failure_at,
                'last_success_at' => $health->last_success_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}