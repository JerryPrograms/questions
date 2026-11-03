<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (! is_string($bearerToken) || ! preg_match('/^(\d+)\|([a-f0-9]{64})$/', $bearerToken, $matches)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $apiToken = ApiToken::query()->with('user')->find((int) $matches[1]);

        if ($apiToken === null || $apiToken->user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($apiToken->expires_at->isPast()) {
            $apiToken->delete();

            return response()->json(['message' => 'API token has expired.'], 401);
        }

        if (! hash_equals($apiToken->token_hash, hash('sha256', $matches[2]))) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $apiToken->update(['last_used_at' => now()]);
        Auth::setUser($apiToken->user);
        $request->setUserResolver(fn () => $apiToken->user);
        $request->attributes->set('api_token', $apiToken);

        return $next($request);
    }
}
