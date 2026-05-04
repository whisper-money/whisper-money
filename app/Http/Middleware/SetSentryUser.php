<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

use function Sentry\configureScope;

class SetSentryUser
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        configureScope(function (Scope $scope) use ($request): void {
            $user = $request->user();

            if ($user === null) {
                return;
            }

            $scope->setUser([
                'id' => (string) $user->getAuthIdentifier(),
                'email' => $user->email,
            ]);
        });

        return $next($request);
    }
}
