<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-body">
    <main class="login-shell">
        <section class="login-intro" aria-label="Portal introduction">
            <div class="brand brand-light"><span class="brand-mark">Q</span><span>Questions<span class="brand-subtitle">Workspace portal</span></span></div>
            <div><span class="eyebrow">ONE PLACE FOR YOUR TEAM</span><h1>A clear view of your workspace.</h1><p>Sign in to manage your team and keep work moving.</p></div>
            <div class="login-footer">Private access for admins and managers</div>
        </section>
        <section class="login-panel" aria-labelledby="login-heading">
            <div class="login-card">
                <span class="eyebrow">WELCOME BACK</span>
                <h2 id="login-heading">Sign in to your account</h2>
                <p class="muted">Use the email and password provided by your admin.</p>
                <form method="POST" action="{{ route('login') }}" class="form-stack" data-ajax>
                    @csrf
                    <div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus><span class="field-error" data-error-for="email" role="alert">@error('email'){{ $message }}@enderror</span></div>
                    <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required><span class="field-error" data-error-for="password" role="alert">@error('password'){{ $message }}@enderror</span></div>
                    <span class="form-error" data-form-error role="alert"></span>
                    <button class="button button-primary button-full" type="submit">Sign in <span aria-hidden="true">→</span></button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
