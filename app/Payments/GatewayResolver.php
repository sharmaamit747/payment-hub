<?php

namespace App\Payments;

use App\Models\PaymentProvider;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Exceptions\GatewayException;

class GatewayResolver
{
    public function resolve(PaymentProvider $provider): PaymentGateway
    {
        $code = strtolower(trim($provider->code));

        $gatewayClass = config("payments.gateways.{$code}");

        if ($gatewayClass === null) {
            throw new GatewayException(
                "No payment gateway configured for provider [{$provider->code}]."
            );
        }

        if (! is_string($gatewayClass) || ! class_exists($gatewayClass)) {
            throw new GatewayException(
                "Payment gateway class [{$gatewayClass}] does not exist."
            );
        }

        $gateway = app($gatewayClass);

        if (! $gateway instanceof PaymentGateway) {
            throw new GatewayException(
                "Payment gateway [{$gatewayClass}] must implement PaymentGateway."
            );
        }

        return $gateway;
    }
}