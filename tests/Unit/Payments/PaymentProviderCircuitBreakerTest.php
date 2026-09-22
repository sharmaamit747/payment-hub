<?php

namespace Tests\Unit\Payments;

use App\Models\PaymentProvider;
use App\Models\PaymentProviderHealth;
use App\Payments\Exceptions\GatewayException;
use App\Payments\Exceptions\GatewayTimeoutException;
use App\Payments\Services\PaymentProviderCircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentProviderCircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    private function createProvider(): PaymentProvider
    {
        $provider = PaymentProvider::factory()->create([
            'code' => 'test_gateway',
            'is_active' => true,
        ]);

        PaymentProviderHealth::create([
            'payment_provider_id' => $provider->id,
            'state' => 'closed',
            'consecutive_failures' => 0,
            'failure_threshold' => 5,
            'recovery_timeout_seconds' => 60,
        ]);

        return $provider;
    }

    public function test_circuit_does_not_open_before_threshold(): void
    {
        $provider = $this->createProvider();

        $breaker = app(PaymentProviderCircuitBreaker::class);

        $exception = new GatewayTimeoutException(
            'Provider timeout.'
        );

        for ($i = 1; $i <= 4; $i++) {
            $breaker->recordFailure(
                $provider,
                $exception
            );
        }

        $health = PaymentProviderHealth::query()
            ->where('payment_provider_id', $provider->id)
            ->firstOrFail();

        $this->assertSame(
            'closed',
            $health->state
        );

        $this->assertSame(
            4,
            $health->consecutive_failures
        );
    }

    public function test_circuit_opens_at_failure_threshold(): void
    {
        $provider = $this->createProvider();

        $breaker = app(PaymentProviderCircuitBreaker::class);

        $exception = new GatewayTimeoutException(
            'Provider timeout.'
        );

        for ($i = 1; $i <= 5; $i++) {
            $breaker->recordFailure(
                $provider,
                $exception
            );
        }

        $health = PaymentProviderHealth::query()
            ->where('payment_provider_id', $provider->id)
            ->firstOrFail();

        $this->assertSame(
            'open',
            $health->state
        );

        $this->assertSame(
            5,
            $health->consecutive_failures
        );

        $this->assertNotNull(
            $health->next_retry_at
        );
    }

    public function test_open_circuit_blocks_requests(): void
    {
        $provider = $this->createProvider();

        $health = PaymentProviderHealth::query()
            ->where('payment_provider_id', $provider->id)
            ->firstOrFail();

        $health->state = 'open';
        $health->consecutive_failures = 5;
        $health->next_retry_at = now()->addMinute();
        $health->save();

        $breaker = app(PaymentProviderCircuitBreaker::class);

        $this->expectException(GatewayException::class);

        $breaker->beforeRequest($provider);
    }

    public function test_open_circuit_enters_half_open_after_recovery_timeout(): void
    {
        $provider = $this->createProvider();

        $health = PaymentProviderHealth::query()
            ->where('payment_provider_id', $provider->id)
            ->firstOrFail();

        $health->state = 'open';
        $health->consecutive_failures = 5;
        $health->opened_at = now()->subSeconds(61);
        $health->next_retry_at = now()->subSecond();
        $health->save();

        $breaker = app(PaymentProviderCircuitBreaker::class);

        $breaker->beforeRequest($provider);

        $health->refresh();

        $this->assertSame(
            'half_open',
            $health->state
        );

        $this->assertNotNull(
            $health->half_opened_at
        );
    }

    public function test_success_closes_circuit_and_resets_failures(): void
    {
        $provider = $this->createProvider();

        $health = PaymentProviderHealth::query()
            ->where('payment_provider_id', $provider->id)
            ->firstOrFail();

        $health->state = 'half_open';
        $health->consecutive_failures = 5;
        $health->half_opened_at = now();
        $health->save();

        $breaker = app(PaymentProviderCircuitBreaker::class);

        $breaker->recordSuccess($provider);

        $health->refresh();

        $this->assertSame(
            'closed',
            $health->state
        );

        $this->assertSame(
            0,
            $health->consecutive_failures
        );

        $this->assertNull(
            $health->half_opened_at
        );

        $this->assertNull(
            $health->next_retry_at
        );
    }

    public function test_non_provider_failure_does_not_trip_circuit(): void
    {
        $provider = $this->createProvider();

        $breaker = app(PaymentProviderCircuitBreaker::class);

        $exception = new \App\Payments\Exceptions\GatewayValidationException(
            'Invalid payment request.'
        );

        $breaker->recordFailure(
            $provider,
            $exception
        );

        $health = PaymentProviderHealth::query()
            ->where('payment_provider_id', $provider->id)
            ->firstOrFail();

        $this->assertSame(
            'closed',
            $health->state
        );

        $this->assertSame(
            0,
            $health->consecutive_failures
        );
    }
}