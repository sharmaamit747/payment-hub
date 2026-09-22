<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentWebhook;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function index(Request $request)
    {
        $query = PaymentWebhook::query()
            ->with([
                'provider',
                'payment',
            ])
            ->latest();

        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->string('status')
            );
        }

        if ($request->filled('provider')) {
            $query->where(
                'payment_provider_id',
                $request->integer('provider')
            );
        }

        if ($request->filled('event_type')) {
            $query->where(
                'event_type',
                'like',
                '%' . $request->string('event_type') . '%'
            );
        }

        $webhooks = $query
            ->paginate(25)
            ->withQueryString();

        return view(
            'admin.webhooks.index',
            compact('webhooks')
        );
    }

    public function show(PaymentWebhook $webhook)
    {
        $webhook->load([
            'provider',
            'payment',
        ]);

        return view(
            'admin.webhooks.show',
            compact('webhook')
        );
    }
}