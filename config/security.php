<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | HTML pages of the web app carry a nonce-based script policy (see
    | App\Http\Middleware\ContentSecurityPolicy), so an injected inline
    | script, inline event handler or javascript: URL does not run.
    |
    | `script_src`: "enforce" (the default) sends it as Content-Security-Policy;
    | "report" sends it as Content-Security-Policy-Report-Only (violations are
    | only logged in the browser console) and keeps frame-ancestors enforced;
    | "off" sends no script policy at all. Anything else counts as "enforce".
    |
    */

    'csp' => [
        'script_src' => env('CSP_SCRIPT_SRC', 'enforce'),
    ],

];
