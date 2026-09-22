<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        @yield('title', 'Dashboard') - Payment Hub
    </title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f4f6;
            color: #111827;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 240px;
            background: #111827;
            color: white;
            padding: 22px 15px;
        }

        .brand {
            font-size: 20px;
            font-weight: 700;
            padding: 0 12px 25px;
        }

        .nav a {
            display: block;
            padding: 11px 12px;
            margin-bottom: 4px;
            color: #d1d5db;
            text-decoration: none;
            border-radius: 6px;
        }

        .nav a:hover {
            background: #1f2937;
            color: white;
        }

        .content {
            flex: 1;
        }

        .topbar {
            height: 65px;
            background: white;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 25px;
        }

        .main {
            padding: 25px;
        }

        .cards {
            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(190px, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .05);
        }

        .card-label {
            color: #6b7280;
            font-size: 14px;
        }

        .card-value {
            font-size: 28px;
            font-weight: 700;
            margin-top: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th,
        td {
            text-align: left;
            padding: 13px;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            font-size: 13px;
            color: #6b7280;
        }

        .status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: 12px;
        }

        .logout {
            border: 0;
            background: transparent;
            color: #374151;
            cursor: pointer;
        }
    </style>

    @stack('styles')
</head>

<body>

    <div class="layout">

        <aside class="sidebar">

            <div class="brand">
                Payment Hub
            </div>

            <nav class="nav">

                <a href="{{ route('admin.dashboard') }}">
                    Dashboard
                </a>

                @can('payments.view')
                    <a href="{{ route('admin.payments.index') }}">
                        Payments
                    </a>
                @endcan

                @can('providers.view')
                    <a href="{{ route('admin.providers.index') }}">
                        Providers
                    </a>
                @endcan

                @can('webhooks.view')
                    <a href="{{ route('admin.webhooks.index') }}">
                        Webhooks
                    </a>
                @endcan

                @can('reconciliation.view')
                    <a href="{{ route('admin.reconciliation.index') }}">
                        Reconciliation
                    </a>
                @endcan

            </nav>

        </aside>

        <div class="content">

            <header class="topbar">

                <strong>
                    @yield('page-title', 'Dashboard')
                </strong>

                <div>
                    {{ auth()->user()->name }}

                    &nbsp; | &nbsp;

                    <form method="POST" action="{{ route('admin.logout') }}" style="display:inline">
                        @csrf

                        <button type="submit" class="logout">
                            Logout
                        </button>
                    </form>
                </div>

            </header>

            <main class="main">

                @if(session('success'))
                    <div class="card">
                        {{ session('success') }}
                    </div>
                @endif

                @if(session('error'))
                    <div class="card">
                        {{ session('error') }}
                    </div>
                @endif

                @yield('content')

            </main>

        </div>

    </div>

    @stack('scripts')

</body>

</html>