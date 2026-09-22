@extends('admin.layouts.app')

@section('title', 'Reconciliation')
@section('page-title', 'Reconciliation')

@section('content')

<div class="card">

    <table>

        <thead>
        <tr>
            <th>ID</th>
            <th>Provider</th>
            <th>Date</th>
            <th>Status</th>
            <th>Processed</th>
            <th>Mismatches</th>
            <th></th>
        </tr>
        </thead>

        <tbody>

        @forelse($reconciliations as $reconciliation)

            <tr>

                <td>{{ $reconciliation->id }}</td>

                <td>
                    {{ $reconciliation->paymentProvider?->code ?? '-' }}
                </td>

                <td>
                    {{ $reconciliation->reconciliation_date ?? '-' }}
                </td>

                <td>
                    {{ $reconciliation->status }}
                </td>

                <td>
                    {{ $reconciliation->processed_count ?? 0 }}
                </td>

                <td>
                    {{ $reconciliation->mismatch_count ?? 0 }}
                </td>

                <td>
                    <a href="{{ route(
                        'admin.reconciliation.show',
                        $reconciliation
                    ) }}">
                        View
                    </a>
                </td>

            </tr>

        @empty

            <tr>
                <td colspan="7">
                    No reconciliation runs found.
                </td>
            </tr>

        @endforelse

        </tbody>

    </table>

    <div style="margin-top:20px">
        {{ $reconciliations->links() }}
    </div>

</div>

@endsection