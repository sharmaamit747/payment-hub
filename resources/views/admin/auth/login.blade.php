<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Payment Hub - Admin Login</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Arial, sans-serif;
            background: #f3f4f6;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            padding: 35px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,.08);
        }

        h1 {
            margin: 0 0 8px;
        }

        .subtitle {
            color: #6b7280;
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            margin-bottom: 18px;
        }

        button {
            width: 100%;
            border: 0;
            padding: 12px;
            border-radius: 7px;
            background: #111827;
            color: white;
            font-size: 15px;
            cursor: pointer;
        }

        .alert {
            padding: 12px;
            margin-bottom: 18px;
            border-radius: 7px;
            background: #fee2e2;
            color: #991b1b;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }
    </style>
</head>

<body>

<div class="login-card">

    <h1>Payment Hub</h1>

    <div class="subtitle">
        Administrator Login
    </div>

    @if(session('error'))
        <div class="alert">
            {{ session('error') }}
        </div>
    @endif

    @if(session('success'))
        <div class="alert success">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <form
        method="POST"
        action="{{ route('admin.login.submit') }}"
    >
        @csrf

        <label for="email">
            Email
        </label>

        <input
            type="email"
            id="email"
            name="email"
            value="{{ old('email') }}"
            required
            autofocus
        >

        <label for="password">
            Password
        </label>

        <input
            type="password"
            id="password"
            name="password"
            required
        >

        <button type="submit">
            Sign In
        </button>
    </form>

</div>

</body>
</html>