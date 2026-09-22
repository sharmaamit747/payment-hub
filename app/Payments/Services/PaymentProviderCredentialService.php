<?php

namespace App\Payments\Services;

use App\Models\PaymentProvider;
use App\Models\PaymentProviderCredential;
use App\Payments\Enums\PaymentEnvironment;
use RuntimeException;

class PaymentProviderCredentialService
{
    public function getActiveCredentials(
        PaymentProvider $provider,
        PaymentEnvironment $environment
    ): PaymentProviderCredential {
        $credential = $provider->credentials()
            ->where('environment', $environment->value)
            ->where('is_active', true)
            ->first();

        if ($credential === null) {
            throw new RuntimeException(
                "No active {$environment->value} credentials configured for payment provider [{$provider->code}]."
            );
        }

        return $credential;
    }
}