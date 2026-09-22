@extends('admin.layouts.app')

@section('title', 'Payments')
@section('page-title', 'Payments')

@section('content')

    <div class="card">

        {{-- ============================================================
        FLASH MESSAGES
        ============================================================= --}}

        @if(session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger">
                {{ session('error') }}
            </div>
        @endif

        @if(session('warning'))
            <div class="alert alert-warning">
                {{ session('warning') }}
            </div>
        @endif

        @if(session('info'))
            <div class="alert alert-info">
                {{ session('info') }}
            </div>
        @endif


        {{-- ============================================================
        FILTERS
        ============================================================= --}}

        <form method="GET" style="
                display:grid;
                grid-template-columns:2fr 1fr 1fr 1fr auto;
                gap:10px;
                margin-bottom:20px
            ">

            <input name="search" placeholder="Merchant / Order / UUID" value="{{ request('search') }}">

            <select name="status">

                <option value="">
                    All Statuses
                </option>

                @foreach([
                        'created',
                        'initiated',
                        'processing',
                        'paid',
                        'failed',
                        'cancelled',
                        'expired'
                    ] as $status)

                    <option value="{{ $status }}" @selected(request('status') === $status)>
                        {{ ucfirst($status) }}
                    </option>

                @endforeach

            </select>


            <input name="currency" placeholder="Currency" value="{{ request('currency') }}">


            <input name="payment_method" placeholder="Method" value="{{ request('payment_method') }}">


            <button type="submit">
                Filter
            </button>

        </form>


        {{-- ============================================================
        PAYMENTS TABLE
        ============================================================= --}}

        <table>

            <thead>

                <tr>

                    <th>
                        Merchant Reference
                    </th>

                    <th>
                        Order
                    </th>

                    <th>
                        Amount
                    </th>

                    <th>
                        Method
                    </th>

                    <th>
                        Provider
                    </th>

                    <th>
                        Status
                    </th>

                    <th>
                        Created
                    </th>

                    <th>
                        Actions
                    </th>

                </tr>

            </thead>


            <tbody>

                @forelse($payments as $payment)

                            @php

                                $status = $payment->status instanceof \BackedEnum
                                    ? $payment->status->value
                                    : (string) $payment->status;

                                $attempt = $payment->attempts->first();

                                $provider = $attempt?->provider;

                            @endphp


                            <tr>

                                {{-- Merchant --}}

                                <td>
                                    {{ $payment->merchant_reference }}
                                </td>


                                {{-- Order --}}

                                <td>
                                    {{ $payment->order_reference }}
                                </td>


                                {{-- Amount --}}

                                <td>

                                    {{ strtoupper($payment->currency) }}

                                    {{ number_format(
                        $payment->amount / 100,
                        2
                    ) }}

                                </td>


                                {{-- Method --}}

                                <td>
                                    {{ strtoupper($payment->payment_method) }}
                                </td>


                                {{-- Provider --}}

                                <td>

                                    @if($provider)

                                                {{ is_object($provider)
                                        ? ($provider->code ?? $provider->name ?? '-')
                                        : $provider
                                                    }}

                                    @else

                                        -

                                    @endif

                                </td>


                                {{-- Status --}}

                                <td>

                                    <span class="status">
                                        {{ $status }}
                                    </span>

                                </td>


                                {{-- Created --}}

                                <td>

                                    {{ $payment->created_at?->format(
                        'd M Y H:i'
                    ) }}

                                </td>


                                {{-- Actions --}}

                                <td>

                                    <div style="
                                            display:flex;
                                            gap:8px;
                                            align-items:center;
                                            flex-wrap:wrap
                                        ">

                                        {{-- View --}}

                                        <a href="{{ route(
                        'admin.payments.show',
                        $payment
                    ) }}">
                                            View
                                        </a>


                                        {{-- Verify --}}

                                        @if($status !== 'paid')

                                                            <form method="POST" action="{{ route(
                                                'admin.payments.verify',
                                                $payment
                                            ) }}" style="display:inline">

                                                                @csrf

                                                                <button type="submit" onclick="
                                                                            return confirm(
                                                                                'Verify this payment with the provider?'
                                                                            )
                                                                        ">
                                                                    Verify
                                                                </button>

                                                            </form>

                                        @else

                                            <span>
                                                ✓ Paid
                                            </span>

                                        @endif

                                    </div>

                                </td>

                            </tr>

                @empty

                    <tr>

                        <td colspan="8">

                            No payments found.

                        </td>

                    </tr>

                @endforelse

            </tbody>

        </table>


        {{-- ============================================================
        PAGINATION
        ============================================================= --}}

        <div style="margin-top:20px">

            {{ $payments->links() }}

        </div>

    </div>

@endsection