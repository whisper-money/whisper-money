# Adding a banking sync provider

This guide explains how to add a new banking/financial provider to the sync
pipeline. Follow it top to bottom — each step is self-contained and the example
(`Acme`) can be copy-pasted and renamed.

It is written so that **a human or an AI agent** can complete the integration
without prior context. If you only need the data to flow in on the scheduled
sync, **Part 1** is all you need. **Part 2** covers the rest of a full
end-to-end integration (connecting the account, mapping it, the UI).

---

## Architecture in one minute

Syncing is split into three responsibilities:

| Piece | Responsibility | Lives in |
| --- | --- | --- |
| `SyncBankingConnectionJob` | **Orchestration** — deleted-user/expiry/rate-limit checks, error handling, retries, logging, status updates. Provider-agnostic. | `app/Jobs/SyncBankingConnectionJob.php` |
| `BankingConnectionSyncer` (one per provider) | **Provider work** — talk to the provider API and persist balances/transactions for each account. | `app/Services/Banking/Sync/*Syncer.php` |
| `BankingConnectionSyncerFactory` | **Dispatch** — maps `connection.provider` (a `BankingProvider` enum) to the right syncer. | `app/Services/Banking/Sync/BankingConnectionSyncerFactory.php` |

The job calls `factory->make($connection)` and then `$syncer->sync(...)`. You
**never edit the job** to add a provider — you add a `BankingProvider` enum case,
a syncer class, and one `match` arm in the factory.

`connection.provider` is the `App\Enums\BankingProvider` enum (the DB column is
cast to it), so it is the single source of truth for the provider identifier and
shared capabilities (`usesApiKey()`, `defaultAccountType()`).

### The contract

Every syncer implements `App\Contracts\BankingConnectionSyncer`:

```php
interface BankingConnectionSyncer
{
    /** Sync every account in the connection. Returns metadata for the sync log. */
    public function sync(BankingConnection $connection, bool $isFirstSync): array;

    /** Whether the connection's consent can expire (consent-based providers). */
    public function expires(): bool;

    /** Whether a permanent auth failure should notify the user (API-key providers). */
    public function notifiesOnAuthFailure(): bool;
}
```

`AbstractBankingConnectionSyncer` provides defaults for the **common case** — an
API-key integration that never expires and emails the user when its credentials
stop working:

```php
public function expires(): bool { return false; }
public function notifiesOnAuthFailure(): bool { return true; }
```

So most providers only implement `sync()`. Override a flag only when your
provider differs:

| Provider kind | `expires()` | `notifiesOnAuthFailure()` | Example |
| --- | --- | --- | --- |
| API-key (user supplies a key/token) | `false` (default) | `true` (default) | Binance, Coinbase, Bitpanda, Indexa Capital, Wise |
| Consent-based (OAuth, expires) | **`true`** | **`false`** | EnableBanking |

This matches `BankingProvider::usesApiKey()` (everything except EnableBanking).
EnableBanking is the only provider that overrides both flags.

---

## Part 1 — Add the sync provider

### Step 1 — Write the API client (if the provider has its own API)

Clients live in `app/Services/Banking/` and wrap the HTTP calls. Look at
`BinanceClient` or `IndexaCapitalClient` as templates. A client typically takes
the credentials in its constructor and exposes the few calls you need:

```php
// app/Services/Banking/AcmeClient.php
namespace App\Services\Banking;

use Illuminate\Support\Facades\Http;

class AcmeClient
{
    private const BASE_URL = 'https://api.acme.com';

    public function __construct(private string $apiToken) {}

    /** @return array<string, mixed> */
    public function getBalances(): array
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->apiToken)
            ->throw()
            ->get('/v1/balances')
            ->json();
    }
}
```

> Throw on 4xx/5xx (`->throw()`). The job relies on `RequestException` to detect
> `401/403` (auth) and `429` (rate limit). For flaky upstreams you can throw
> `App\Exceptions\Banking\TransientBankingProviderException` instead — the job
> treats it as a temporary error (logged as a warning, retried).

### Step 2 — Write the sync service(s)

The service maps provider data onto our models (`Account`, `AccountBalance`,
`Transaction`). Reuse the existing services as templates:

- Balances only (crypto/investment): `BinanceBalanceSyncService`, `BitpandaBalanceSyncService`.
- Balances **and** transactions (banks): `TransactionSyncService` + `BalanceSyncService` (EnableBanking), or `WiseTransactionSyncService` + `WiseBalanceSyncService`.

These are resolved from the container, so type-hint their dependencies in the
constructor and they get injected.

### Step 3 — Write the syncer

Create `app/Services/Banking/Sync/AcmeSyncer.php`. Extend
`AbstractBankingConnectionSyncer`, inject your sync service(s), and implement
`sync()`. Loop over `$connection->accounts` and do the provider work.

```php
namespace App\Services\Banking\Sync;

use App\Models\BankingConnection;
use App\Services\Banking\AcmeBalanceSyncService;
use App\Services\Banking\AcmeClient;

class AcmeSyncer extends AbstractBankingConnectionSyncer
{
    public function __construct(private AcmeBalanceSyncService $balanceSync) {}

    public function sync(BankingConnection $connection, bool $isFirstSync): array
    {
        $client = new AcmeClient($connection->api_token);

        $connection->load('accounts');

        foreach ($connection->accounts as $account) {
            $this->balanceSync->sync($account, $client);
        }

        return []; // or ['transactions_synced' => N, ...] — stored on the sync log
    }
}
```

Notes:

- **Credentials** are on the connection: `$connection->api_token` and
  `$connection->api_secret` (both `encrypted` casts, decrypted automatically).
- **Return value** is metadata persisted on `BankingSyncLog.metadata`. Return
  `[]` if there's nothing useful. See `WiseSyncer` for a transaction-count example.
- **`$isFirstSync`** is `true` on the very first sync or a forced full sync. Use
  it to backfill history once (see `BinanceSyncer`/`CoinbaseSyncer`).
- Override `expires()` / `notifiesOnAuthFailure()` here if your provider is not
  the default API-key shape (see the table above).

### Step 4 — Register the provider (enum case + factory arm)

First add the identifier to `app/Enums/BankingProvider.php`:

```php
enum BankingProvider: string
{
    // ...existing cases...
    case Acme = 'acme'; // the value stored in banking_connections.provider
}
```

If your provider is **not** the default shape, also reflect it on the enum:
`usesApiKey()` (auth-failed email; `true` for everything except EnableBanking)
and `defaultAccountType()` (the account type its accounts default to — e.g.
`Investment` for crypto, `Checking` for banks).

Then add one `match` arm in
`app/Services/Banking/Sync/BankingConnectionSyncerFactory.php`:

```php
$syncer = match ($connection->provider) {
    // ...existing arms...
    BankingProvider::Acme => AcmeSyncer::class,
};
```

The `match` is **exhaustive over the enum** — there is no `default`. If you add
an enum case without a syncer arm, Larastan flags the non-exhaustive `match` and
the factory throws `\UnhandledMatchError` at runtime, so you cannot forget this
step. That is the **only** wiring the sync pipeline needs.

### Step 5 — Add a factory state (for tests)

In `database/factories/BankingConnectionFactory.php`, add a state mirroring the
other providers so tests can build connections easily:

```php
public function acme(): static
{
    return $this->state(fn (array $attributes) => [
        'provider' => BankingProvider::Acme,
        'authorization_id' => null,
        'session_id' => null,
        'api_token' => 'test-acme-token-'.fake()->uuid(),
        'aspsp_name' => 'Acme',
        'aspsp_country' => 'ES',
        'valid_until' => null, // null = never expires (API-key provider)
    ]);
}
```

### Step 6 — Test it

Two layers of tests:

**a) Factory wiring** — extend the dataset in
`tests/Feature/OpenBanking/BankingConnectionSyncerFactoryTest.php`:

```php
'acme' => [BankingProvider::Acme, AcmeSyncer::class],
```

The `it('covers every provider enum case')` test already fails for any enum case
without a syncer. Add your flag expectations too if you overrode a default.

**b) Sync behavior** — add a test in
`tests/Feature/OpenBanking/SyncBankingConnectionJobTest.php`. Drive the job
through the shared `runSync()` helper (defined in `tests/Pest.php`) and fake the
provider HTTP with `Http::fake()`:

```php
test('acme sync stores balances', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->acme()->create([
        'user_id' => $user->id,
        'last_synced_at' => null,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);

    Http::fake(['api.acme.com/*' => Http::response(['balances' => [/* ... */]])]);

    $job = new SyncBankingConnectionJob($connection);
    runSync($job); // resolves the real factory → AcmeSyncer

    $connection->refresh();
    expect($connection->last_synced_at)->not->toBeNull();
    expect($account->balances()->count())->toBeGreaterThan(0);
});
```

> `runSync($job, $transactionSync, $balanceSync)` also accepts mocked
> `TransactionSyncService` / `BalanceSyncService` and binds them into the
> container — only needed for EnableBanking-style providers that use those
> shared services.

Run them:

```bash
php artisan test --compact tests/Feature/OpenBanking/SyncBankingConnectionJobTest.php \
                          tests/Feature/OpenBanking/BankingConnectionSyncerFactoryTest.php
```

---

## Part 2 — Full end-to-end integration

Part 1 makes the scheduled sync work. To let a user actually **connect** an Acme
account, you also need the pieces below. Use an existing API-key provider
(e.g. Binance) as the reference implementation and grep for its name.

1. **Connection + onboarding controller** — `app/Http/Controllers/OpenBanking/`.
   Each provider has a controller (e.g. `BinanceController`) that validates
   credentials, creates the `BankingConnection`, and stores `pending_accounts_data`.
   Add an `AcmeController`, a Form Request for its credentials, and its routes.

2. **Model helper (optional)** — `app/Models/BankingConnection.php` exposes
   `isBinance()`, `isCoinbase()`, etc. Add `isAcme()` only if other code needs to
   branch on the provider. The sync pipeline does **not** need it (it uses the
   factory), so don't add it speculatively.

3. **Account mapping** — the created account type comes from
   `BankingProvider::defaultAccountType()` (used by `AccountMappingController` and
   `Concerns/CreatesAccountsFromPending`). You already set this in Step 4 when you
   added the enum case, so there is nothing extra to wire here.

4. **Frontend** — the connect flow lives under `resources/js/pages/` /
   `resources/js/components/`. Mirror an existing provider's form and provider
   list entry.

> Search the codebase for an existing provider's identifier
> (e.g. `grep -rn "'binance'" app resources`) to find every touchpoint to mirror.

---

## Checklist & CI

Sync pipeline (Part 1):

- [ ] `BankingProvider::Acme` enum case (+ `usesApiKey()`/`defaultAccountType()` if non-default)
- [ ] `AcmeClient` (if the provider has an API)
- [ ] `AcmeBalanceSyncService` / transaction service
- [ ] `AcmeSyncer extends AbstractBankingConnectionSyncer`
- [ ] flag overrides if not a default API-key provider
- [ ] one `match` arm in `BankingConnectionSyncerFactory`
- [ ] `acme()` factory state
- [ ] factory-wiring + sync-behavior tests, green

Before finalizing, run the project checks:

```bash
vendor/bin/pint --dirty            # PHP formatting
vendor/bin/phpstan analyse         # static analysis
php artisan test --exclude-testsuite=Browser --compact
```
