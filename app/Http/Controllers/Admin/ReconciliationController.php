<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentReconciliation;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function index(Request $request)
    {
        $query = PaymentReconciliation::query()
            ->latest();

        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->string('status')
            );
        }

        $reconciliations = $query
            ->paginate(25)
            ->withQueryString();

        return view(
            'admin.reconciliations.index',
            compact('reconciliations')
        );
    }

    public function show(PaymentReconciliation $reconciliation)
    {
        return view(
            'admin.reconciliations.show',
            compact('reconciliation')
        );
    }
}