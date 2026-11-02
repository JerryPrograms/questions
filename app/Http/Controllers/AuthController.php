<?php

namespace App\Http\Controllers;

use App\Mail\LoginVerificationCode;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
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
        $data = $request->validate(['code' => ['required', 'digits:6']]);
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
        AuditLog::record($request, 'auth.login_succeeded', 'Signed in successfully with email verification.', 'user', $user->id);

        $redirect = $user->role === 'manager'
            ? redirect()->route('manager.dashboard')
            : redirect()->route('admin.imports.create');

        return $request->expectsJson() ? response()->json(['redirect' => $redirect->getTargetUrl()]) : $redirect;
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

    private function maskEmail(string $email): string
    {
        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, 1).str_repeat('•', max(2, mb_strlen($name) - 1)).'@'.$domain;
    }
}
