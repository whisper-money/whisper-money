<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the actions an account with public credentials must never perform.
 *
 * Three modes, because two different audiences need blocking:
 *
 * - `auto` (registered globally): every shared account, but only the handful of
 *   routes that would lock everybody else out of the login. It runs on every web
 *   request, so it stays a fixed path list rather than "all mutating methods".
 * - `demo`: the demo account alone, every mutating method. Used where the press
 *   account must keep working — creating MCP tokens, above all.
 * - no argument: every shared account, every mutating method.
 */
class BlockSharedAccountActions
{
    /**
     * Routes that would hand one visitor control of a login everybody shares.
     * Fortify registers them outside the app's own route files, so they are
     * matched by path.
     *
     * @var list<string>
     */
    private array $hijackRoutes = [
        'user/two-factor-authentication',
        'user/confirmed-two-factor-authentication',
        'user/two-factor-recovery-codes',
    ];

    /** @var list<string> */
    private array $blockedMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->applies($user, $mode)) {
            return $next($request);
        }

        if (! in_array($request->method(), $this->blockedMethods, true)) {
            return $next($request);
        }

        if ($mode === 'auto' && ! in_array($request->path(), $this->hijackRoutes, true)) {
            return $next($request);
        }

        return $this->refuse($request);
    }

    private function applies(User $user, ?string $mode): bool
    {
        return $mode === 'demo'
            ? $user->isRestrictedDemoAccount()
            : $user->isRestrictedSharedAccount();
    }

    /**
     * The error key stays `demo` for both audiences: it is the bag key the
     * existing settings pages and tests already read.
     */
    private function refuse(Request $request): Response
    {
        $message = 'This action is not available on a shared account.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return back()->withErrors(['demo' => $message]);
    }
}
