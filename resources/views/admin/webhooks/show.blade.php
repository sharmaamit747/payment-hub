@extends('admin.layouts.app')

@section('title', 'Webhook Details')
@section('page-title', 'Webhook Details')

@section('content')

<div class="card">

    <table>

        <tr>
            <th>Provider</th>
            <td>{{ $webhook->provider?->code }}</td>
        </tr>

        <tr>
            <th>Event</th>
            <td>{{ $webhook->event_type }}</td>
        </tr>

        <tr>
            <th>Provider Event ID</th>
            <td>{{ $webhook->provider_event_id }}</td>
        </tr>

        <tr>
            <th>Status</th>
            <td>{{ $webhook->status->value ?? $webhook->status }}</td>
        </tr>

        <tr>
            <th>Attempts</th>
            <td>{{ $webhook->attempts }}</td>
        </tr>

        <tr>
            <th>Received</th>
            <td>{{ $webhook->received_at }}</td>
        </tr>

        <tr>
            <th>Processed</th>
            <td>{{ $webhook->processed_at ?? '-' }}</td>
        </tr>

        <tr>
            <th>Error</th>
            <td>{{ $webhook->error_message ?? '-' }}</td>
        </tr>

    </table>

</div>

<div class="card">

    <h2>Payload</h2>

    <pre style="overflow:auto">{{ json_encode(
        $webhook->payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) }}</pre>

</div>

@endsection