<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?? $request->header('X-Api-Token');

        if (! $token || $token !== config('services.logbird.api_token')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user = User::query()->first();

        if (! $user) {
            return response()->json(['error' => 'No user found'], 404);
        }

        Auth::login($user);

        return $next($request);
    }
}
