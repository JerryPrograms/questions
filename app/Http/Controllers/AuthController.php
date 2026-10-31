<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        if (! Auth::attempt($credentials)) {
            if ($request->expectsJson()) {
                return response()->json(['errors' => ['email' => ['These credentials do not match our records.']]], 422);
            }

            return back()->withErrors(['email' => 'These credentials do not match our records.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        $redirect = auth()->user()->role === 'manager'
            ? redirect()->route('manager.dashboard')
            : redirect()->route('admin.imports.create');

        return $request->expectsJson() ? response()->json(['redirect' => $redirect->getTargetUrl()]) : $redirect;
    }

    public function logout(Request $request): RedirectResponse|JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson() ? response()->json(['redirect' => route('login')]) : redirect()->route('login');
    }
}
