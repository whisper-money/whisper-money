# Live Enable Banking e2e check

`connect-bank.mjs` drives the **real Enable Banking sandbox** end-to-end against the
running dev server, covering the flows we must never regress:

1. **Settings → connect** a bank (Banco de Sabadell), map accounts, and sync transactions.
2. **Onboarding → connect** a bank (BBVA) with auto-created accounts.
3. **Settings → reconnect** an expired connection.
4. **Session lost on return** — connect, then drop the app session before the bank
   redirect lands, and confirm the user still sees the "connected — go back to your
   app" screen (the iOS-PWA / Safari case) instead of being bounced to login.

It uses the sandbox test credentials (`user1` / `1234`, OTP `012345`).

## Why a standalone script and not a Pest test

Enable Banking only redirects to the **fixed registered redirect URI**
(`https://whisper.money.local/open-banking/callback`). A Pest browser test runs an
ephemeral server on a random port with a `RefreshDatabase` transactional database that
the external redirect can never reach, so the live flow cannot complete inside one.

This script instead drives the **persistent dev server**, lets the bank redirect to the
registered host (which is not served locally), captures the `code`+`state` it carries,
and replays the callback against the dev server. The callback is stateless — it resolves
the connection from the `state_token` — so the replay finalizes the connection correctly.

For CI regression coverage of the same three flows with the provider faked, see
`tests/Browser/BankConnectionFlowTest.php`.

## Prerequisites

- `composer run dev` running. Note the app port it prints (default `8921`).
- Valid `ENABLEBANKING_*` config and private key (the same config the app uses), with
  `ENABLEBANKING_REDIRECT_URL` pointing at the registered `whisper.money.local` callback.
- Pending migrations applied (`php artisan migrate`).

## Run

```bash
# one-liner (auto-detects the running dev server port)
composer e2e:banking

# or directly
node tests/Browser/live/connect-bank.mjs

# watch it run in a headed browser / override the base url
HEADLESS=0 node tests/Browser/live/connect-bank.mjs
APP_BASE_URL=http://127.0.0.1:8921 node tests/Browser/live/connect-bank.mjs
```

The script seeds its own users via `php artisan e2e:banking-fixture seed`
(local/testing only), reports `PASS`/`FAIL` per scenario, and exits non-zero on failure.

### Videos & screenshots

Everything is written to `tests/Browser/live/videos/` (cleared at the start of each run,
git-ignored):

- `<scenario>.mp4` — a recording of the whole flow. A visible cursor follows the mouse
  and pulses red on every click so you can track what's happening. Encoded as mp4
  (H.264) so it plays in QuickTime / Preview / browsers. Needs `ffmpeg`; without it the
  raw `.webm` is kept instead.
- `<scenario>.png` — a still of the final screen (guaranteed clear, independent of video
  timing).
- `failure-<scenario>.png` — a full-page screenshot, written only when a scenario fails.

Scenarios: `settings-connect`, `onboarding-connect`, `reconnect-expired`,
`session-lost-return`.

## Notes

- The Enable Banking and bank sandbox pages are driven in English; the script forces an
  `en-US` browser locale so the button labels match.
- The transaction sync runs on the default queue (the dev worker only drains `emails`),
  so the script triggers `php artisan banking:sync --connection=<id> --sync` to verify
  transactions pull through.
