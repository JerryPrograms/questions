<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify sign in · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-body">
    <main class="login-shell">
        <section class="login-intro" aria-label="Portal introduction">
            <div class="brand brand-light"><span class="brand-mark">Q</span><span>Questions<span class="brand-subtitle">Workspace portal</span></span></div>
            <div><span class="eyebrow">SECURE ACCESS</span><h1>One quick check.</h1><p>Use the code sent to your email to securely continue into the workspace.</p></div>
            <div class="login-footer">Private access for admins and managers</div>
        </section>
        <section class="login-panel" aria-labelledby="verification-heading">
            <div class="login-card verification-card">
                <span class="eyebrow">EMAIL VERIFICATION</span>
                <h2 id="verification-heading">Enter your 6-digit code</h2>
                <p class="muted">We sent a code to <strong>{{ $email }}</strong>. It expires in 2 minutes.</p>
                @if (session('status'))<div class="success-message" role="status">{{ session('status') }}</div>@endif
                <form method="POST" action="{{ route('login.verify.submit') }}" class="form-stack" data-ajax>
                    @csrf
                    <div class="field"><label for="code">Verification code</label><input class="verification-code-input" id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus><span class="field-error" data-error-for="code" role="alert">@error('code'){{ $message }}@enderror</span></div>
                    <span class="form-error" data-form-error role="alert"></span>
                    <button class="button button-primary button-full" type="submit">Verify and sign in <span aria-hidden="true">→</span></button>
                </form>
                <div class="verification-actions">
                    <form method="POST" action="{{ route('login.verify.resend') }}">@csrf<button class="text-link" type="submit">Send a new code</button></form>
                    <a class="text-link" href="{{ route('login') }}">Back to sign in</a>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
