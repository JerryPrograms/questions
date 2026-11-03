<?php

namespace App\Http\Controllers;

use App\Mail\LoginVerificationCode;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const TRUSTED_DEVICE_COOKIE = 'trusted_device';

    private const TRUSTED_DEVICE_MINUTES = 43200;

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse|JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::validate($credentials)) {
            AuditLog::record($request, 'auth.login_failed', 'Failed sign-in attempt for '.$credentials['email'].'.', 'authentication', null, null, null, ['email' => $credentials['email']]);

            if ($request->expectsJson()) {
                return response()->json(['errors' => ['email' => ['These credentials do not match our records.']]], 422);
            }

            return back()->withErrors(['email' => 'These credentials do not match our records.'])->onlyInput('email');
        }

        $user = User::query()->where('email', $credentials['email'])->firstOrFail();

        if ($this->isTrustedDevice($request, $user)) {
            Auth::login($user);
            $request->session()->forget('login_two_factor');
            $request->session()->regenerate();
            AuditLog::record($request, 'auth.login_succeeded', 'Signed in successfully from a trusted device.', 'user', $user->id);

            return $this->authenticatedResponse($request, $user);
        }

        $this->sendVerificationCode($request, $user);
        $redirect = redirect()->route('login.verify');

        return $request->expectsJson() ? response()->json(['redirect' => $redirect->getTargetUrl()]) : $redirect;
    }

    public function showVerification(Request $request): View|RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login')->withErrors(['email' => 'Sign in to request a verification code.']);
        }

        return view('auth.verify-login', [
            'email' => $this->maskEmail($user->email),
        ]);
    }

    public function verify(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'digits:6'],
            'trust_device' => ['sometimes', 'boolean'],
        ]);
        $pending = $request->session()->get('login_two_factor');
        $user = $this->pendingUser($request);

        if ($user === null || ! is_array($pending)) {
            throw ValidationException::withMessages(['code' => 'Sign in again to request a new code.']);
        }

        if (now()->timestamp >= $pending['expires_at']) {
            AuditLog::record($request, 'auth.two_factor_expired', 'Expired login verification code submitted.', 'user', $user->id);
            throw ValidationException::withMessages(['code' => 'This code has expired. Request a new code.']);
        }

        if (! Hash::check($data['code'], $pending['code_hash'])) {
            $attempts = $pending['attempts'] + 1;
            $request->session()->put('login_two_factor.attempts', $attempts);
            AuditLog::record($request, 'auth.two_factor_failed', 'Invalid login verification code submitted.', 'user', $user->id);

            if ($attempts >= 5) {
                $request->session()->forget('login_two_factor');
                throw ValidationException::withMessages(['code' => 'Too many incorrect attempts. Sign in again.']);
            }

            throw ValidationException::withMessages(['code' => 'The verification code is incorrect.']);
        }

        Auth::login($user);
        $request->session()->forget('login_two_factor');
        $request->session()->regenerate();

        if ($data['trust_device'] ?? false) {
            $this->trustDevice($request, $user);
        }

        AuditLog::record($request, 'auth.login_succeeded', 'Signed in successfully with email verification.', 'user', $user->id);

        return $this->authenticatedResponse($request, $user);
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login')->withErrors(['email' => 'Sign in to request a verification code.']);
        }

        $this->sendVerificationCode($request, $user);

        return redirect()->route('login.verify')->with('status', 'A new verification code has been sent.');
    }

    public function logout(Request $request): RedirectResponse|JsonResponse
    {
        AuditLog::record($request, 'auth.logout', 'Signed out.', 'user', $request->user()->id);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson() ? response()->json(['redirect' => route('login')]) : redirect()->route('login');
    }

    private function sendVerificationCode(Request $request, User $user): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Mail::to($user)->send(new LoginVerificationCode($code));
        $request->session()->regenerate();
        $request->session()->put('login_two_factor', [
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(2)->timestamp,
            'attempts' => 0,
        ]);
        AuditLog::record($request, 'auth.two_factor_sent', 'Sent a login verification code.', 'user', $user->id);
    }

    private function pendingUser(Request $request): ?User
    {
        $userId = $request->session()->get('login_two_factor.user_id');

        return is_int($userId) ? User::query()->find($userId) : null;
    }

    private function authenticatedResponse(Request $request, User $user): RedirectResponse|JsonResponse
    {
        $redirect = $user->role === 'manager'
            ? redirect()->route('manager.dashboard')
            : redirect()->route('admin.imports.create');

        return $request->expectsJson() ? response()->json(['redirect' => $redirect->getTargetUrl()]) : $redirect;
    }

    private function isTrustedDevice(Request $request, User $user): bool
    {
        $cookie = $request->cookie(self::TRUSTED_DEVICE_COOKIE);

        if (! is_string($cookie) || ! preg_match('/^(\d+)\|([A-Za-z0-9]+)$/', $cookie, $matches)) {
            return false;
        }

        $trustedDevice = TrustedDevice::query()
            ->whereKey((int) $matches[1])
            ->where('user_id', $user->id)
            ->first();

        if ($trustedDevice === null) {
            return false;
        }

        if ($trustedDevice->expires_at->isPast()) {
            $trustedDevice->delete();

            return false;
        }

        if (! hash_equals($trustedDevice->token_hash, hash('sha256', $matches[2]))) {
            return false;
        }

        $trustedDevice->update(['last_used_at' => now()]);

        return true;
    }

    private function trustDevice(Request $request, User $user): void
    {
        TrustedDevice::query()
            ->where('user_id', $user->id)
            ->where('expires_at', '<=', now())
            ->delete();

        $token = Str::random(64);
        $trustedDevice = TrustedDevice::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::TRUSTED_DEVICE_MINUTES),
        ]);

        Cookie::queue(
            self::TRUSTED_DEVICE_COOKIE,
            $trustedDevice->id.'|'.$token,
            self::TRUSTED_DEVICE_MINUTES,
            '/',
            null,
            config('session.secure'),
            true,
            false,
            'strict',
        );
        AuditLog::record($request, 'auth.trusted_device_created', 'Trusted this device for 30 days.', 'user', $user->id, null, null, [
            'expires_at' => $trustedDevice->expires_at->toIso8601String(),
        ]);
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, 1).str_repeat('•', max(2, mb_strlen($name) - 1)).'@'.$domain;
    }
}
