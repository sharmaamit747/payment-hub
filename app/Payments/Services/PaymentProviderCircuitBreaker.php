<?php

namespace App\Payments\Services;

use App\Models\PaymentProvider;
use App\Models\PaymentProviderHealth;
use App\Payments\Exceptions\GatewayAuthenticationException;
use App\Payments\Exceptions\GatewayException;
use App\Payments\Exceptions\GatewayTimeoutException;
use App\Payments\Exceptions\GatewayValidationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class PaymentProviderCircuitBreaker
{
    public function beforeRequest(PaymentProvider $provider): void
    {
        DB::transaction(function () use ($provider) {
            $health = $this->getHealthForUpdate($provider);

            /*
             * CLOSED
             *
             * Normal provider operation.
             */
            if ($health->isClosed()) {
                return;
            }

            /*
             * OPEN
             */
            if ($health->isOpen()) {
                /*
                 * Recovery window has not elapsed.
                 */
                if (
                    $health->next_retry_at !== null
                    && now()->lt($health->next_retry_at)
                ) {
                    throw new GatewayException(
                        "Payment provider [{$provider->code}] circuit is open."
                    );
                }

                /*
                 * Recovery window elapsed.
                 *
                 * Lock guarantees only one request can change
                 * OPEN -> HALF_OPEN.
                 */
                $health->state = 'half_open';
                $health->half_opened_at = now();
                $health->save();

                return;
            }

            /*
             * HALF_OPEN
             *
             * Only one probe is allowed.
             */
            if ($health->isHalfOpen()) {

                /*
                 * If an existing probe has been running longer
                 * than the recovery timeout, consider it stuck.
                 */
                $probeTimeout = now()->subSeconds(
                    $health->recovery_timeout_seconds
                );

                if (
                    $health->half_opened_at !== null
                    && $health->half_opened_at->lte($probeTimeout)
                ) {
                    /*
                     * Reuse HALF_OPEN as the next probe window.
                     */
                    $health->half_opened_at = now();
                    $health->save();

                    return;
                }

                throw new GatewayException(
                    "Payment provider [{$provider->code}] circuit is recovering."
                );
            }
        });
    }

    public function recordSuccess(PaymentProvider $provider): void
    {
        DB::transaction(function () use ($provider) {
            $health = $this->getHealthForUpdate($provider);

            /*
             * A successful request closes the circuit and
             * resets the failure counter.
             */
            $health->state = 'closed';
            $health->consecutive_failures = 0;
            $health->opened_at = null;
            $health->half_opened_at = null;
            $health->next_retry_at = null;
            $health->last_success_at = now();

            $health->save();
        });
    }

    public function recordFailure(
        PaymentProvider $provider,
        Throwable $exception
    ): void {
        /*
         * Do not count business/authentication/validation failures
         * as provider health failures.
         */
        if (!$this->shouldTrip($exception)) {
            return;
        }

        DB::transaction(function () use ($provider) {
            $health = $this->getHealthForUpdate($provider);

            $health->consecutive_failures++;
            $health->last_failure_at = now();

            /*
             * IMPORTANT:
             *
             * Do not open the circuit until the configured
             * failure threshold is reached.
             */
            if (
                $health->consecutive_failures
                >= $health->failure_threshold
            ) {
                $health->state = 'open';

                $health->opened_at = now();

                $health->half_opened_at = null;

                $health->next_retry_at = now()->addSeconds(
                    $health->recovery_timeout_seconds
                );
            }

            $health->save();
        });
    }

    public function isAvailable(
        PaymentProvider $provider
    ): bool {
        $health = $this->getHealth($provider);

        /*
         * CLOSED = available.
         */
        if ($health->isClosed()) {
            return true;
        }

        /*
         * OPEN and recovery window not reached.
         */
        if (
            $health->isOpen()
            && $health->next_retry_at !== null
            && now()->lt($health->next_retry_at)
        ) {
            return false;
        }

        /*
         * HALF_OPEN means a probe can be attempted.
         */
        if ($health->isHalfOpen()) {
            return false;
        }

        /*
         * OPEN with recovery window elapsed.
         *
         * beforeRequest() will atomically transition it
         * to HALF_OPEN.
         */
        return $health->isOpen();
    }

    public function getHealth(
        PaymentProvider $provider
    ): PaymentProviderHealth {
        return PaymentProviderHealth::firstOrCreate(
            [
                'payment_provider_id' => $provider->id,
            ],
            [
                'state' => 'closed',
                'consecutive_failures' => 0,
                'failure_threshold' => 5,
                'recovery_timeout_seconds' => 60,
            ]
        );
    }

    private function getHealthForUpdate(
        PaymentProvider $provider
    ): PaymentProviderHealth {
        $health = PaymentProviderHealth::query()
            ->where('payment_provider_id', $provider->id)
            ->lockForUpdate()
            ->first();

        if ($health) {
            return $health;
        }

        return PaymentProviderHealth::create([
            'payment_provider_id' => $provider->id,
            'state' => 'closed',
            'consecutive_failures' => 0,
            'failure_threshold' => 5,
            'recovery_timeout_seconds' => 60,
        ]);
    }

    private function shouldTrip(
        Throwable $exception
    ): bool {
        /*
         * Authentication errors normally indicate bad credentials,
         * not provider availability.
         */
        if ($exception instanceof GatewayAuthenticationException) {
            return false;
        }

        /*
         * Validation/business errors are not provider outages.
         */
        if ($exception instanceof GatewayValidationException) {
            return false;
        }

        /*
         * Network/provider timeout should affect provider health.
         */
        if ($exception instanceof GatewayTimeoutException) {
            return true;
        }

        /*
         * Generic gateway failures count as provider failures.
         */
        return $exception instanceof GatewayException;
    }
}