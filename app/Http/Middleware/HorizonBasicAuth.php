<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HorizonBasicAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $username = config('horizon.basic_auth_username');
        $password = config('horizon.basic_auth_password');

        if (empty($username) || empty($password)) {
            return $next($request);
        }

        $authUser = $request->getUser();
        $authPassword = $request->getPassword();

        if ($authUser !== $username || $authPassword !== $password) {
            return response('Unauthorized', 401, [
                'WWW-Authenticate' => 'Basic realm="Horizon"',
            ]);
        }

        return $next($request);
    }
}
