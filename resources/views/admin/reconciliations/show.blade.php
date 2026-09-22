@extends('admin.layouts.app')

@section('title', 'Reconciliation Details')
@section('page-title', 'Reconciliation Details')

@section('content')

<div class="card">

    <table>

        <tr>
            <th>ID</th>
            <td>{{ $reconciliation->id }}</td>
        </tr>

        <tr>
            <th>Status</th>
            <td>{{ $reconciliation->status }}</td>
        </tr>

        <tr>
            <th>Date</th>
            <td>{{ $reconciliation->reconciliation_date ?? '-' }}</td>
        </tr>

        <tr>
            <th>Processed</th>
            <td>{{ $reconciliation->processed_count ?? 0 }}</td>
        </tr>

        <tr>
            <th>Mismatches</th>
            <td>{{ $reconciliation->mismatch_count ?? 0 }}</td>
        </tr>

        <tr>
            <th>Started</th>
            <td>{{ $reconciliation->started_at ?? '-' }}</td>
        </tr>

        <tr>
            <th>Completed</th>
            <td>{{ $reconciliation->completed_at ?? '-' }}</td>
        </tr>

    </table>

</div>

@endsection