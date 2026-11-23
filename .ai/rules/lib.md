---
paths:
  - 'resources/js/lib/{sentry,failed-navigation-toast,unattended-requests,leave-page}.ts'
---

# Lib

## HttpNetworkError in Sentry is deliberate residue, not a bug to fix
`HttpNetworkError: Network error (<url>)` from Inertia's XHR client keeps appearing in Sentry (issue PHP-LARAVEL-5E, and PHP-LARAVEL-5C on /login). Do not "fix" it in code — it is already handled on both sides:

- The user gets a toast: `installFailedNavigationToast()` listens on `router.on('networkError')`, with suppression for page-leave aborts and unattended requests (prefetch/poll), speaking up anyway after 3 lost beats.
- Sentry drops the noise: `beforeSend` runs `isPageLeaveAbortNoise` and `isUnattendedRequestNoise`.

What still reaches Sentry is the intended signal: an attended request that genuinely failed for a user who stayed put. The filters match Inertia's message tail narrowly on purpose — if the format changes they stop matching and the noise returns, which is the safe direction. The remaining lever is Sentry-side (an ignore rule or a rate threshold), a product call, not a code change. Same class as PHP-LARAVEL-28 (`AxiosError: Network Error`), already ignored in Sentry.

## Inertia's own 409 hard visits are page departures (PHP-LARAVEL-84)
A `409` with `X-Inertia-Location` (the asset-version mismatch after every deploy, or an external redirect) makes Inertia assign `window.location` itself, bypassing `leavePage()`/`reloadPage()`. The aborts it causes (requests in flight, clicks during the reload) used to reach Sentry as `HttpNetworkError` and trigger the failed-navigation toast. `trackInertiaLocationDepartures()` (installed in app.tsx) sets the departure flag on Inertia's `location` event, except for a version change answering only async visits (polls), which Inertia does not reload for. Don't widen the Sentry message patterns for this; the flag is the fix.
