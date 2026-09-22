@extends('admin.layouts.app')

@section('title', 'Provider')
@section('page-title', 'Provider Details')

@section('content')

<div class="card">

    <h2>{{ $provider->name }}</h2>

    <table>

        <tr>
            <th>Code</th>
            <td>{{ $provider->code }}</td>
        </tr>

        <tr>
            <th>Priority</th>
            <td>{{ $provider->priority }}</td>
        </tr>

        <tr>
            <th>Active</th>
            <td>{{ $provider->is_active ? 'Yes' : 'No' }}</td>
        </tr>

        <tr>
            <th>Version</th>
            <td>{{ $provider->version }}</td>
        </tr>

    </table>

</div>

<div class="card">

    <h2>Credentials</h2>

    <p>
        Credentials are intentionally never displayed.
    </p>

    <table>

        <thead>
        <tr>
            <th>Environment</th>
            <th>Active</th>
            <th>Created</th>
        </tr>
        </thead>

        <tbody>

        @foreach($provider->credentials as $credential)

            <tr>
                <td>{{ $credential->environment }}</td>
                <td>{{ $credential->is_active ? 'Yes' : 'No' }}</td>
                <td>{{ $credential->created_at }}</td>
            </tr>

        @endforeach

        </tbody>

    </table>

</div>

<div class="card">

    <h2>Routing Rules</h2>

    <table>

        <thead>
        <tr>
            <th>Country</th>
            <th>Currency</th>
            <th>Method</th>
            <th>Priority</th>
            <th>Active</th>
        </tr>
        </thead>

        <tbody>

        @foreach($provider->routingRules as $rule)

            <tr>
                <td>{{ $rule->country_code }}</td>
                <td>{{ $rule->currency }}</td>
                <td>{{ $rule->payment_method }}</td>
                <td>{{ $rule->priority }}</td>
                <td>{{ $rule->is_active ? 'Yes' : 'No' }}</td>
            </tr>

        @endforeach

        </tbody>

    </table>

</div>

@endsection