<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ApiAuthController extends Controller
{
    private const TOKEN_LIFETIME_DAYS = 30;

    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! $user->can('admin') || ! Hash::check($credentials['password'], $user->password)) {
            AuditLog::record($request, 'api.token_failed', 'Failed API token request.', 'authentication', null, null, null, [
                'email' => $credentials['email'],
            ]);

            return response()->json(['message' => 'Invalid administrator credentials.'], 401);
        }

        ApiToken::query()
            ->where('user_id', $user->id)
            ->where('expires_at', '<=', now())
            ->delete();

        $secret = bin2hex(random_bytes(32));
        $apiToken = ApiToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $secret),
            'expires_at' => now()->addDays(self::TOKEN_LIFETIME_DAYS),
        ]);
        $request->setUserResolver(fn () => $user);
        AuditLog::record($request, 'api.token_created', 'Created an API access token.', 'api_token', $apiToken->id, null, null, [
            'expires_at' => $apiToken->expires_at->toIso8601String(),
        ]);

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $apiToken->id.'|'.$secret,
            'expires_at' => $apiToken->expires_at->toIso8601String(),
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var ApiToken $apiToken */
        $apiToken = $request->attributes->get('api_token');
        AuditLog::record($request, 'api.token_revoked', 'Revoked the current API access token.', 'api_token', $apiToken->id);
        $apiToken->delete();

        return response()->json(['message' => 'API token revoked.']);
    }
}
