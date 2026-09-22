@extends('admin.layouts.app')

@section('title', 'Webhooks')
@section('page-title', 'Webhooks')

@section('content')

<div class="card">

    <form method="GET" style="display:flex;gap:10px;margin-bottom:20px">

        <input
            name="event_type"
            placeholder="Event type"
            value="{{ request('event_type') }}"
        >

        <select name="status">
            <option value="">All Statuses</option>

            @foreach([
                'received',
                'processing',
                'processed',
                'failed',
                'ignored'
            ] as $status)

                <option
                    value="{{ $status }}"
                    @selected(request('status') === $status)
                >
                    {{ ucfirst($status) }}
                </option>

            @endforeach
        </select>

        <button type="submit">
            Filter
        </button>

    </form>

    <table>

        <thead>
        <tr>
            <th>Provider</th>
            <th>Event</th>
            <th>Provider Event ID</th>
            <th>Status</th>
            <th>Attempts</th>
            <th>Received</th>
            <th></th>
        </tr>
        </thead>

        <tbody>

        @forelse($webhooks as $webhook)

            <tr>

                <td>
                    {{ $webhook->provider?->code ?? '-' }}
                </td>

                <td>
                    {{ $webhook->event_type }}
                </td>

                <td>
                    {{ $webhook->provider_event_id }}
                </td>

                <td>
                    {{ $webhook->status->value ?? $webhook->status }}
                </td>

                <td>
                    {{ $webhook->attempts }}
                </td>

                <td>
                    {{ $webhook->received_at }}
                </td>

                <td>
                    <a href="{{ route('admin.webhooks.show', $webhook) }}">
                        View
                    </a>
                </td>

            </tr>

        @empty

            <tr>
                <td colspan="7">
                    No webhooks found.
                </td>
            </tr>

        @endforelse

        </tbody>

    </table>

    <div style="margin-top:20px">
        {{ $webhooks->links() }}
    </div>

</div>

@endsection