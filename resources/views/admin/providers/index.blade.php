@extends('admin.layouts.app')

@section('title', 'Providers')
@section('page-title', 'Payment Providers')

@section('content')

<div class="card">

    <table>

        <thead>
        <tr>
            <th>Provider</th>
            <th>Name</th>
            <th>Priority</th>
            <th>Active</th>
            <th>Credentials</th>
            <th>Routing Rules</th>
            <th></th>
        </tr>
        </thead>

        <tbody>

        @forelse($providers as $provider)

            <tr>

                <td>
                    {{ $provider->code }}
                </td>

                <td>
                    {{ $provider->name }}
                </td>

                <td>
                    {{ $provider->priority }}
                </td>

                <td>
                    {{ $provider->is_active ? 'Yes' : 'No' }}
                </td>

                <td>
                    {{ $provider->credentials->count() }}
                </td>

                <td>
                    {{ $provider->routingRules->count() }}
                </td>

                <td>
                    <a href="{{ route('admin.providers.show', $provider) }}">
                        View
                    </a>
                </td>

            </tr>

        @empty

            <tr>
                <td colspan="7">
                    No providers configured.
                </td>
            </tr>

        @endforelse

        </tbody>

    </table>

</div>

@endsection