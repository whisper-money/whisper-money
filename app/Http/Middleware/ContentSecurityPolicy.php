<?php

namespace App\Http\Middleware;

use App\Support\ContentSecurityPolicyHeader as Policy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * A nonce-based script policy for the HTML pages of the web app: only scripts
 * carrying this request's nonce run, and the scripts they load
 * ('strict-dynamic': Vite's chunks, the widget loader's `import()` of its
 * data: modules). An injected `<script>`, inline event handler
 * (`<img onerror>`) or `javascript:` URL does not.
 *
 * Vite tags its scripts and preloads with the nonce (Vite::useCspNonce()),
 * and every inline script of the app's Blade views prints it. Inline styles
 * are used widely, so there is no style-src. JSON answers (Inertia visits,
 * the API, MCP) carry no policy: they are not documents. Neither does the
 * debug error page, whose inline scripts carry no nonce, outside production.
 *
 * `security.csp.script_src` switches it: enforce, report (Report-Only, with
 * frame-ancestors still enforced) or off.
 */
class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = config('security.csp.script_src');

        if ($mode === 'off') {
            return $next($request);
        }

        $nonce = Vite::useCspNonce();
        $response = $next($request);

        if (! $this->isPage($response)) {
            return $response;
        }

        $scripts = [
            'script-src' => "'nonce-{$nonce}' 'strict-dynamic' 'self'",
            'object-src' => "'none'",
            'base-uri' => "'self'",
            'worker-src' => "'self' blob:",
        ];
        $framing = ['frame-ancestors' => "'none'"];

        if ($mode === 'report') {
            Policy::merge($response, $scripts, Policy::ReportOnlyHeader);
            Policy::merge($response, $framing);
        } else {
            Policy::merge($response, [...$scripts, ...$framing]);
        }

        return $response;
    }

    /**
     * An HTML document of the app, and not the debug error page.
     */
    private function isPage(Response $response): bool
    {
        $isHtml = str_starts_with((string) $response->headers->get('Content-Type'), 'text/html');
        $isDebugError = $response->getStatusCode() >= 500 && config()->boolean('app.debug');

        return $isHtml && ! $isDebugError;
    }
}
