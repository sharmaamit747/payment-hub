<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentProvider;
use App\Models\PaymentProviderCredential;
use App\Models\PaymentRoutingRule;
use App\Models\PaymentProviderHealth;

class ProviderController extends Controller
{
    public function index()
    {
        $providers = PaymentProvider::query()
            ->with([
                'credentials',
                'routingRules',
                'health',
            ])
            ->orderBy('priority')
            ->get();

        return view(
            'admin.providers.index',
            compact('providers')
        );
    }

    public function show(PaymentProvider $provider)
    {
        $provider->load([
            'credentials',
            'routingRules',
            'health',
        ]);

        return view(
            'admin.providers.show',
            compact('provider')
        );
    }
}