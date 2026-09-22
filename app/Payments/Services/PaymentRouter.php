<?php

namespace App\Payments\Services;

use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\PaymentRoutingRule;
use RuntimeException;

class PaymentRouter
{
    public function __construct(
        private PaymentProviderCircuitBreaker $circuitBreaker
    ) {
    }

    public function route(Payment $payment): PaymentProvider
    {
        $rules = PaymentRoutingRule::query()
            ->with('provider')
            ->where('is_active', true)
            ->whereHas('provider', function ($query) {
                $query->where('is_active', true);
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            $provider = $rule->provider;

            if (!$this->matches($rule, $payment)) {
                continue;
            }

            if (!$this->circuitBreaker->isAvailable($provider)) {
                continue;
            }

            return $provider;
        }

        throw new RuntimeException(
            "No payment provider available for payment [{$payment->uuid}]."
        );
    }

    private function matches(
        PaymentRoutingRule $rule,
        Payment $payment
    ): bool {
        if (
            $rule->country_code !== null &&
            strtoupper($rule->country_code) !== strtoupper(
                (string) ($payment->metadata['country_code'] ?? '')
            )
        ) {
            return false;
        }

        if (
            $rule->currency !== null &&
            strtoupper($rule->currency) !== strtoupper($payment->currency)
        ) {
            return false;
        }

        if (
            $rule->payment_method !== null &&
            strtolower($rule->payment_method) !== strtolower(
                (string) $payment->payment_method
            )
        ) {
            return false;
        }

        if (
            $rule->min_amount !== null &&
            $payment->amount < $rule->min_amount
        ) {
            return false;
        }

        if (
            $rule->max_amount !== null &&
            $payment->amount > $rule->max_amount
        ) {
            return false;
        }

        return true;
    }
}