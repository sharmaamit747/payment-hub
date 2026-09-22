<?php

namespace App\Payments\Services;

use App\Models\PaymentProvider;
use App\Models\PaymentProviderCredential;
use App\Payments\Enums\PaymentEnvironment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentProviderService
{
    public function createProvider(
        string $code,
        string $name,
        int $priority = 100,
        ?array $config = null
    ): PaymentProvider {
        $code = strtolower(trim($code));

        if ($code === '') {
            throw new InvalidArgumentException(
                'Payment provider code is required.'
            );
        }

        if ($name === '') {
            throw new InvalidArgumentException(
                'Payment provider name is required.'
            );
        }

        return DB::transaction(function () use (
            $code,
            $name,
            $priority,
            $config
        ) {
            return PaymentProvider::create([
                'code' => $code,
                'name' => $name,
                'is_active' => false,
                'priority' => $priority,
                'config' => $config,
                'version' => 1,
            ]);
        });
    }

    public function updateProvider(
        PaymentProvider $provider,
        string $name,
        int $priority,
        ?array $config = null
    ): PaymentProvider {
        return DB::transaction(function () use (
            $provider,
            $name,
            $priority,
            $config
        ) {
            $provider->update([
                'name' => trim($name),
                'priority' => $priority,
                'config' => $config,
                'version' => $provider->version + 1,
            ]);

            return $provider->refresh();
        });
    }

    public function activateProvider(
        PaymentProvider $provider
    ): PaymentProvider {
        return DB::transaction(function () use ($provider) {
            $provider->update([
                'is_active' => true,
                'version' => $provider->version + 1,
            ]);

            return $provider->refresh();
        });
    }

    public function deactivateProvider(
        PaymentProvider $provider
    ): PaymentProvider {
        return DB::transaction(function () use ($provider) {
            $provider->update([
                'is_active' => false,
                'version' => $provider->version + 1,
            ]);

            return $provider->refresh();
        });
    }

    public function saveCredentials(
        PaymentProvider $provider,
        PaymentEnvironment $environment,
        ?string $publicKey,
        ?string $secretKey,
        ?string $webhookSecret,
        ?array $additionalCredentials = null
    ): PaymentProviderCredential {
        return DB::transaction(function () use (
            $provider,
            $environment,
            $publicKey,
            $secretKey,
            $webhookSecret,
            $additionalCredentials
        ) {
            return PaymentProviderCredential::updateOrCreate(
                [
                    'payment_provider_id' => $provider->id,
                    'environment' => $environment->value,
                ],
                [
                    'public_key' => $publicKey,
                    'secret_key' => $secretKey,
                    'webhook_secret' => $webhookSecret,
                    'additional_credentials' => $additionalCredentials,
                ]
            );
        });
    }

    public function activateCredentials(
        PaymentProviderCredential $credential
    ): PaymentProviderCredential {
        $credential->update([
            'is_active' => true,
        ]);

        return $credential->refresh();
    }

    public function deactivateCredentials(
        PaymentProviderCredential $credential
    ): PaymentProviderCredential {
        $credential->update([
            'is_active' => false,
        ]);

        return $credential->refresh();
    }
}