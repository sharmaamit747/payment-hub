@extends('admin.layouts.app')

@section('title', 'Payment Details')
@section('page-title', 'Payment Details')

@section('content')

@php

    /*
    |--------------------------------------------------------------------------
    | Safe Payment Status
    |--------------------------------------------------------------------------
    */

    $paymentStatus = $payment->status instanceof \BackedEnum
        ? $payment->status->value
        : (string) $payment->status;


    /*
    |--------------------------------------------------------------------------
    | Payment Status Display
    |--------------------------------------------------------------------------
    */

    $paymentStatusLabel = ucfirst(
        str_replace(
            '_',
            ' ',
            $paymentStatus
        )
    );

@endphp


{{-- ================================================================
     FLASH MESSAGES
================================================================ --}}

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



{{-- ================================================================
     ACTION BAR
================================================================ --}}

<div
    style="
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:10px;
        margin-bottom:20px;
        flex-wrap:wrap;
    "
>

    <div>

        <a
            href="{{ route('admin.payments.index') }}"
        >
            ← Back to Payments
        </a>

    </div>


    <div
        style="
            display:flex;
            gap:10px;
            align-items:center;
            flex-wrap:wrap;
        "
    >

        {{-- ========================================================
             VERIFY PAYMENT
        ========================================================= --}}

        @if($paymentStatus !== 'paid')

            <form
                method="POST"
                action="{{ route(
                    'admin.payments.verify',
                    $payment
                ) }}"
            >

                @csrf

                <button
                    type="submit"
                    onclick="
                        return confirm(
                            'Verify this payment with the provider?'
                        )
                    "
                >
                    Verify Payment
                </button>

            </form>

        @else

            <span>
                ✓ Payment Paid
            </span>

        @endif


        {{-- ========================================================
             REFUNDS
        ========================================================= --}}

        @if(
            in_array(
                $paymentStatus,
                [
                    'paid',
                    'partially_refunded'
                ],
                true
            )
        )

            <a
                href="{{ route(
                    'admin.payments.refunds',
                    $payment
                ) }}"
            >
                Refunds
            </a>

        @endif

    </div>

</div>



{{-- ================================================================
     SUMMARY CARDS
================================================================ --}}

<div class="cards">


    {{-- STATUS --}}

    <div class="card">

        <div class="card-label">
            Status
        </div>

        <div class="card-value">

            {{ $paymentStatusLabel }}

        </div>

    </div>



    {{-- AMOUNT --}}

    <div class="card">

        <div class="card-label">
            Amount
        </div>

        <div class="card-value">

            {{ strtoupper($payment->currency) }}

            {{ number_format(
                $payment->amount / 100,
                2
            ) }}

        </div>

    </div>



    {{-- AMOUNT PAID --}}

    <div class="card">

        <div class="card-label">
            Amount Paid
        </div>

        <div class="card-value">

            {{ strtoupper($payment->currency) }}

            {{ number_format(
                $payment->amount_paid / 100,
                2
            ) }}

        </div>

    </div>


</div>



{{-- ================================================================
     PAYMENT INFORMATION
================================================================ --}}

<div class="card">

    <h2>
        Payment Information
    </h2>


    <table>

        <tr>

            <th>
                UUID
            </th>

            <td>
                {{ $payment->uuid }}
            </td>

        </tr>


        <tr>

            <th>
                Merchant Reference
            </th>

            <td>
                {{ $payment->merchant_reference }}
            </td>

        </tr>


        <tr>

            <th>
                Order Reference
            </th>

            <td>
                {{ $payment->order_reference }}
            </td>

        </tr>


        <tr>

            <th>
                Currency
            </th>

            <td>
                {{ strtoupper($payment->currency) }}
            </td>

        </tr>


        <tr>

            <th>
                Amount
            </th>

            <td>

                {{ strtoupper($payment->currency) }}

                {{ number_format(
                    $payment->amount / 100,
                    2
                ) }}

            </td>

        </tr>


        <tr>

            <th>
                Amount Paid
            </th>

            <td>

                {{ strtoupper($payment->currency) }}

                {{ number_format(
                    $payment->amount_paid / 100,
                    2
                ) }}

            </td>

        </tr>


        <tr>

            <th>
                Payment Method
            </th>

            <td>
                {{ strtoupper($payment->payment_method) }}
            </td>

        </tr>


        <tr>

            <th>
                Country
            </th>

            <td>
                {{ $payment->country_code }}
            </td>

        </tr>


        <tr>

            <th>
                Description
            </th>

            <td>
                {{ $payment->description ?: '-' }}
            </td>

        </tr>


        <tr>

            <th>
                Status
            </th>

            <td>
                {{ $paymentStatusLabel }}
            </td>

        </tr>


        <tr>

            <th>
                Created
            </th>

            <td>
                {{ $payment->created_at }}
            </td>

        </tr>


        <tr>

            <th>
                Paid At
            </th>

            <td>
                {{ $payment->paid_at ?? '-' }}
            </td>

        </tr>


    </table>

</div>



{{-- ================================================================
     PAYMENT ATTEMPTS
================================================================ --}}

<div class="card">

    <h2>
        Payment Attempts
    </h2>


    <table>

        <thead>

        <tr>

            <th>
                Attempt
            </th>

            <th>
                Provider
            </th>

            <th>
                Provider Order
            </th>

            <th>
                Provider Payment
            </th>

            <th>
                Status
            </th>

            <th>
                Created
            </th>

        </tr>

        </thead>


        <tbody>

        @forelse($payment->attempts as $attempt)

            @php

                $attemptStatus =
                    $attempt->status instanceof \BackedEnum
                        ? $attempt->status->value
                        : (string) $attempt->status;

                /*
                 * PaymentAttempt relationship is provider(),
                 * NOT paymentProvider().
                 */

                $provider = $attempt->provider;

            @endphp


            <tr>

                <td>

                    {{ $attempt->attempt_uuid }}

                </td>


                <td>

                    @if($provider)

                        {{ $provider->code
                            ?? $provider->name
                            ?? '-'
                        }}

                    @else

                        -

                    @endif

                </td>


                <td>

                    {{ $attempt->provider_order_id
                        ?? '-'
                    }}

                </td>


                <td>

                    {{ $attempt->provider_payment_id
                        ?? '-'
                    }}

                </td>


                <td>

                    {{ ucfirst(
                        str_replace(
                            '_',
                            ' ',
                            $attemptStatus
                        )
                    ) }}

                </td>


                <td>

                    {{ $attempt->created_at }}

                </td>

            </tr>


        @empty

            <tr>

                <td colspan="6">

                    No attempts.

                </td>

            </tr>

        @endforelse

        </tbody>

    </table>

</div>



{{-- ================================================================
     TRANSACTIONS
================================================================ --}}

<div class="card">

    <h2>
        Transactions
    </h2>


    <table>

        <thead>

        <tr>

            <th>
                Type
            </th>

            <th>
                Amount
            </th>

            <th>
                Status
            </th>

            <th>
                Provider Transaction
            </th>

            <th>
                Idempotency Key
            </th>

            <th>
                Created
            </th>

        </tr>

        </thead>


        <tbody>

        @forelse($payment->transactions as $transaction)

            @php

                $transactionType =
                    $transaction->type instanceof \BackedEnum
                        ? $transaction->type->value
                        : (string) $transaction->type;

                $transactionStatus =
                    $transaction->status instanceof \BackedEnum
                        ? $transaction->status->value
                        : (string) $transaction->status;

            @endphp


            <tr>

                <td>

                    {{ ucfirst(
                        str_replace(
                            '_',
                            ' ',
                            $transactionType
                        )
                    ) }}

                </td>


                <td>

                    {{ strtoupper($payment->currency) }}

                    {{ number_format(
                        $transaction->amount / 100,
                        2
                    ) }}

                </td>


                <td>

                    {{ ucfirst(
                        str_replace(
                            '_',
                            ' ',
                            $transactionStatus
                        )
                    ) }}

                </td>


                <td>

                    {{ $transaction->provider_transaction_id
                        ?? '-'
                    }}

                </td>


                <td>

                    {{ $transaction->idempotency_key
                        ?? '-'
                    }}

                </td>


                <td>

                    {{ $transaction->created_at }}

                </td>

            </tr>


        @empty

            <tr>

                <td colspan="6">

                    No transactions.

                </td>

            </tr>

        @endforelse

        </tbody>

    </table>

</div>



{{-- ================================================================
     WEBHOOKS
================================================================ --}}

<div class="card">

    <h2>
        Webhooks
    </h2>


    <table>

        <thead>

        <tr>

            <th>
                Event
            </th>

            <th>
                Provider Event
            </th>

            <th>
                Status
            </th>

            <th>
                Received
            </th>

        </tr>

        </thead>


        <tbody>

        @forelse($webhooks as $webhook)

            @php

                $webhookStatus =
                    $webhook->status instanceof \BackedEnum
                        ? $webhook->status->value
                        : (string) $webhook->status;

            @endphp


            <tr>

                <td>

                    {{ $webhook->event_type }}

                </td>


                <td>

                    {{ $webhook->provider_event_id
                        ?? '-'
                    }}

                </td>


                <td>

                    {{ ucfirst(
                        str_replace(
                            '_',
                            ' ',
                            $webhookStatus
                        )
                    ) }}

                </td>


                <td>

                    {{ $webhook->received_at
                        ?? $webhook->created_at
                    }}

                </td>

            </tr>


        @empty

            <tr>

                <td colspan="4">

                    No webhooks.

                </td>

            </tr>

        @endforelse

        </tbody>

    </table>

</div>



{{-- ================================================================
     METADATA
================================================================ --}}

@if(!empty($payment->metadata))

    <div class="card">

        <h2>
            Metadata
        </h2>


        <pre style="
            white-space:pre-wrap;
            word-break:break-word;
            background:#f5f5f5;
            padding:15px;
            border-radius:6px;
        ">{{ json_encode(
            $payment->metadata,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        ) }}</pre>

    </div>

@endif


@endsection