@extends('admin.layouts.app')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')

<div class="cards">

    <div class="card">
        <div class="card-label">
            Total Payments
        </div>

        <div class="card-value">
            {{ number_format($stats['payments']) }}
        </div>
    </div>

    <div class="card">
        <div class="card-label">
            Successful
        </div>

        <div class="card-value">
            {{ number_format($stats['successful_payments']) }}
        </div>
    </div>

    <div class="card">
        <div class="card-label">
            Pending
        </div>

        <div class="card-value">
            {{ number_format($stats['pending_payments']) }}
        </div>
    </div>

    <div class="card">
        <div class="card-label">
            Failed
        </div>

        <div class="card-value">
            {{ number_format($stats['failed_payments']) }}
        </div>
    </div>

    <div class="card">
        <div class="card-label">
            Total Amount
        </div>

        <div class="card-value">
            ₹{{ number_format($stats['total_amount'] / 100, 2) }}
        </div>
    </div>

    <div class="card">
        <div class="card-label">
            Paid Amount
        </div>

        <div class="card-value">
            ₹{{ number_format($stats['paid_amount'] / 100, 2) }}
        </div>
    </div>

    <div class="card">
        <div class="card-label">
            Refunds
        </div>

        <div class="card-value">
            {{ number_format($stats['refunds']) }}
        </div>
    </div>

    <div class="card">
        <div class="card-label">
            Webhook Failures
        </div>

        <div class="card-value">
            {{ number_format($stats['failed_webhooks']) }}
        </div>
    </div>

</div>

<div class="card">

    <h2>
        Recent Payments
    </h2>

    <table>

        <thead>
            <tr>
                <th>Merchant Reference</th>
                <th>Order</th>
                <th>Amount</th>
                <th>Currency</th>
                <th>Status</th>
                <th>Created</th>
            </tr>
        </thead>

        <tbody>

        @forelse($recentPayments as $payment)

            <tr>

                <td>
                    {{ $payment->merchant_reference }}
                </td>

                <td>
                    {{ $payment->order_reference }}
                </td>

                <td>
                    {{ number_format($payment->amount / 100, 2) }}
                </td>

                <td>
                    {{ strtoupper($payment->currency) }}
                </td>

                <td>
                    <span class="status">
                        {{ is_object($payment->status)
                            ? $payment->status->value
                            : $payment->status }}
                    </span>
                </td>

                <td>
                    {{ $payment->created_at->format('d M Y H:i') }}
                </td>

            </tr>

        @empty

            <tr>
                <td colspan="6">
                    No payments found.
                </td>
            </tr>

        @endforelse

        </tbody>

    </table>

</div>

@endsection