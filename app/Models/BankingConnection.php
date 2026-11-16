<?php

namespace App\Models;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Concerns\BelongsToSpace;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Database\Factories\BankingConnectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property bool $has_pending_accounts
 * @property bool $can_sync_manually
 * @property bool $failed_on_wrong_login
 * @property bool $is_rate_limited
 * @property bool $is_beta
 * @property Carbon|null $next_sync_attempt_at
 * @property string|null $aspsp_name
 * @property string|null $aspsp_country
 * @property BankingProvider $provider
 * @property BankingConnectionStatus $status
 * @property Carbon|null $valid_until
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $ledger_synced_until
 * @property Carbon|null $bank_transactions_email_cutoff_at
 * @property Carbon|null $rate_limited_until
 * @property Carbon|null $interactive_rate_limited_until
 * @property int $consecutive_sync_failures
 * @property array<int, array<string, mixed>>|null $pending_accounts_data
 */
class BankingConnection extends Model
{
    /**
     * Stored in English and translated where it is shown, because the
     * connections page has to recognise it whatever language the user reconnected in.
     */
    public const string WRONG_LOGIN_ERROR = 'This bank login does not cover the accounts on this connection, so the connection was left as it was. Try again with the login you used for them.';

    /** @use HasFactory<BankingConnectionFactory> */
    use BelongsToSpace, HasFactory, HasUuids, SoftDeletes;

    /**
     * How many days before `valid_until` the consent counts as expiring soon:
     * the window in which we warn the user by email and offer an early renewal,
     * so a consent never lapses into days of broken syncing.
     */
    public const EXPIRY_WARNING_DAYS = 7;

    protected $fillable = [
        'user_id',
        'space_id',
        'provider',
        'authorization_id',
        'state_token',
        'session_id',
        'aspsp_name',
        'aspsp_country',
        'aspsp_logo',
        'status',
        'valid_until',
        'last_synced_at',
        'ledger_synced_until',
        'bank_transactions_email_cutoff_at',
        'error_message',
        'rate_limited_until',
        'interactive_rate_limited_until',
        'consecutive_sync_failures',
        'pending_accounts_data',
        'api_token',
        'api_secret',
    ];

    protected $hidden = [
        'space_id',
        'api_token',
        'api_secret',
        'pending_accounts_data',
        'authorization_id',
        'state_token',
        'session_id',
        // Superseded by `isBeta()`; kept only until the column is dropped.
        'aspsp_beta',
    ];

    /**
     * How this connection identifies itself in a log line.
     *
     * Which bank it is decides whether a wave of failures is one connector
     * misbehaving or the provider as a whole, and that is the first question
     * every sync investigation starts with.
     *
     * @return array<string, string|null>
     */
    public function logContext(): array
    {
        return [
            'connection_id' => $this->id,
            'aspsp_name' => $this->aspsp_name,
            'aspsp_country' => $this->aspsp_country,
        ];
    }

    /**
     * Whether this connection's bank is on our curated beta list.
     */
    public function isBeta(): bool
    {
        return $this->provider->isBetaBank((string) $this->aspsp_name, $this->aspsp_country);
    }

    protected function casts(): array
    {
        return [
            'provider' => BankingProvider::class,
            'status' => BankingConnectionStatus::class,
            'valid_until' => 'datetime',
            'last_synced_at' => 'datetime',
            'ledger_synced_until' => 'datetime',
            'bank_transactions_email_cutoff_at' => 'datetime',
            'rate_limited_until' => 'datetime',
            'interactive_rate_limited_until' => 'datetime',
            'pending_accounts_data' => 'array',
            'api_token' => 'encrypted',
            'api_secret' => 'encrypted',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** @return HasMany<BankingSyncLog, $this> */
    public function syncLogs(): HasMany
    {
        return $this->hasMany(BankingSyncLog::class);
    }

    /** @return HasOne<BankingSyncLog, $this> */
    public function latestSyncLog(): HasOne
    {
        return $this->hasOne(BankingSyncLog::class)->latestOfMany();
    }

    /**
     * Connections a scheduled sync should pick up: Active ones and Error ones
     * still inside their retry budget, with an unexpired consent, plus Active
     * Enable Banking ones whose consent ran out (the sync marks those expired),
     * all outside a rate-limit backoff and owned by a user that still exists.
     *
     * @param  Builder<BankingConnection>  $query
     * @return Builder<BankingConnection>
     */
    public function scopeEligibleForScheduledSync(Builder $query): Builder
    {
        return $query
            ->whereHas('user')
            ->where(function (Builder $query) {
                $query->where(function (Builder $query) {
                    $query->where(function (Builder $query) {
                        $query->where('status', BankingConnectionStatus::Active)
                            ->orWhere(function (Builder $query) {
                                $query->where('status', BankingConnectionStatus::Error)
                                    ->where('consecutive_sync_failures', '<', SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES);
                            });
                    })->where(function (Builder $query) {
                        $query->whereNull('valid_until')
                            ->orWhere('valid_until', '>', now());
                    });
                })->orWhere(fn (Builder $query) => $query->consentExpiredWhileActive());
            })
            ->where(function (Builder $query) {
                $query->whereNull('rate_limited_until')
                    ->orWhere('rate_limited_until', '<=', now());
            });
    }

    /**
     * Active Enable Banking connections whose consent has run out. They are still
     * dispatched so the sync job can mark them expired and tell the user; that
     * path never reaches the bank.
     *
     * @param  Builder<BankingConnection>  $query
     * @return Builder<BankingConnection>
     */
    public function scopeConsentExpiredWhileActive(Builder $query): Builder
    {
        return $query->where('provider', BankingProvider::EnableBanking)
            ->where('status', BankingConnectionStatus::Active)
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', now());
    }

    /**
     * Connections that stopped syncing for good until the user acts: expired
     * ones (flagged, or an Enable Banking consent past its `valid_until` the
     * sync has not flagged yet) and errored ones out of their scheduled retry
     * budget, which no scheduled sync picks up again. An errored connection
     * still inside the budget is left out: the next cycle may well recover it.
     *
     * @param  Builder<BankingConnection>  $query
     * @return Builder<BankingConnection>
     */
    public function scopeNeedingReconnect(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->where('status', BankingConnectionStatus::Expired)
                ->orWhere(function (Builder $query) {
                    $query->where('provider', BankingProvider::EnableBanking)
                        ->whereIn('status', [BankingConnectionStatus::Active, BankingConnectionStatus::Error])
                        ->whereNotNull('valid_until')
                        ->where('valid_until', '<=', now());
                })
                ->orWhere(function (Builder $query) {
                    $query->where('status', BankingConnectionStatus::Error)
                        ->where('consecutive_sync_failures', '>=', SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES);
                });
        });
    }

    /**
     * Connections that have not synced successfully since $since. Never-synced
     * connections count as not synced.
     *
     * @param  Builder<BankingConnection>  $query
     * @return Builder<BankingConnection>
     */
    public function scopeNotSyncedSince(Builder $query, CarbonInterface $since): Builder
    {
        return $query->where(function (Builder $query) use ($since) {
            $query->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<', $since);
        });
    }

    /**
     * Where a reconnect that did not go through leaves the connection. While the
     * consent runs that is Error, which the sweep still retries and which finds
     * the old session working or expires it. Once the consent has lapsed the sweep
     * no longer takes an Error connection, and the settings page only offers
     * Reconnect on some errors, so it is Expired.
     */
    public function statusAfterFailedReconnect(): BankingConnectionStatus
    {
        return $this->valid_until?->isPast()
            ? BankingConnectionStatus::Expired
            : BankingConnectionStatus::Error;
    }

    /**
     * Whether a reconnect left this connection needing another try with the
     * right login.
     */
    public function failedOnWrongLogin(): bool
    {
        return $this->error_message === self::WRONG_LOGIN_ERROR;
    }

    public function isActive(): bool
    {
        return $this->status === BankingConnectionStatus::Active;
    }

    /**
     * Point the connection at a freshly authorized provider session.
     *
     * Reconnecting is the way out of a parked connection, so it has to hand back
     * the full retry budget. Carrying the old count over meant the first failure
     * after a reconnect could re-park it immediately, which is the state the user
     * just paid SCA to leave.
     *
     * @param  array<string, mixed>  $sessionData
     * @param  array<string, mixed>  $attributes
     */
    public function activateWithSession(array $sessionData, array $attributes = []): void
    {
        $this->update([
            'session_id' => $sessionData['session_id'],
            'status' => BankingConnectionStatus::Active,
            'valid_until' => $sessionData['access']['valid_until'] ?? null,
            'error_message' => null,
            'consecutive_sync_failures' => 0,
            ...$attributes,
        ]);
    }

    public function isIndexaCapital(): bool
    {
        return $this->provider === BankingProvider::IndexaCapital;
    }

    public function isBinance(): bool
    {
        return $this->provider === BankingProvider::Binance;
    }

    public function isBitpanda(): bool
    {
        return $this->provider === BankingProvider::Bitpanda;
    }

    public function isCoinbase(): bool
    {
        return $this->provider === BankingProvider::Coinbase;
    }

    public function isEnableBanking(): bool
    {
        return $this->provider === BankingProvider::EnableBanking;
    }

    public function isInteractiveBrokers(): bool
    {
        return $this->provider === BankingProvider::InteractiveBrokers;
    }

    public function usesApiKey(): bool
    {
        return $this->provider->usesApiKey();
    }

    public function isWise(): bool
    {
        return $this->provider === BankingProvider::Wise;
    }

    public function hasPendingAccounts(): bool
    {
        return ! empty($this->pending_accounts_data);
    }

    /**
     * Pending accounts the user can actually map. Providers sometimes report accounts
     * without a uid (EnableBanking does it for some French cards); those can never be
     * synced, so offering them for mapping only produces a validation error. A uid
     * a sibling connection already syncs is left out too: a bank that shares one
     * session lists the sibling's accounts here, and creating them would import
     * their history a second time.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mappablePendingAccounts(): array
    {
        $siblingUids = $this->uidsHeldBySiblings();

        return array_values(array_filter(
            $this->pending_accounts_data ?? [],
            fn (array $account): bool => ! empty($account['uid']) && ! in_array($account['uid'], $siblingUids, true),
        ));
    }

    /**
     * The uids the user's other connections sync. Only Enable Banking can share
     * a session between connections.
     *
     * @return list<string>
     */
    private function uidsHeldBySiblings(): array
    {
        if (! $this->isEnableBanking()) {
            return [];
        }

        return Account::query()
            ->where('user_id', $this->user_id)
            ->whereNotNull('banking_connection_id')
            ->where('banking_connection_id', '!=', $this->getKey())
            ->whereNotNull('external_account_id')
            ->pluck('external_account_id')
            ->all();
    }

    /**
     * Names of the pending accounts left out of the mapping screen, so the user is told
     * why an account they can see in their bank never shows up here.
     *
     * @return array<int, string>
     */
    public function unmappablePendingAccountNames(): array
    {
        return array_values(array_map(
            fn (array $account): string => $account['name'] ?? $account['account_id']['iban'] ?? __('Bank Account'),
            array_filter(
                $this->pending_accounts_data ?? [],
                fn (array $account): bool => empty($account['uid']),
            ),
        ));
    }

    public function isExpired(): bool
    {
        return $this->status === BankingConnectionStatus::Expired
            || ($this->valid_until && $this->valid_until->isPast());
    }

    /**
     * Whether the consent is close enough to running out that the user should
     * renew it now, before syncing breaks. Only EnableBanking connections carry
     * a `valid_until`, so nothing else is ever expiring soon.
     */
    public function isExpiringSoon(): bool
    {
        return $this->valid_until !== null
            && ! $this->isExpired()
            && $this->valid_until->isBefore(now()->addDays(self::EXPIRY_WARNING_DAYS));
    }

    public function isRateLimited(): bool
    {
        return $this->rate_limited_until !== null
            && $this->rate_limited_until->isFuture();
    }

    /**
     * Whether the backoff stops a sync, given whether the user is present for it.
     *
     * PSD2 meters the access we make on our own - around four calls a day per
     * account - apart from the access the user asks for while using the app, and
     * Enable Banking tells the two apart by the PSU headers. So the backoff a
     * refused background sync left does not stop an EnableBanking sync the user
     * is present for; only a refusal to such a sync does, and that one is kept in
     * `interactive_rate_limited_until`.
     */
    public function isRateLimitedFor(bool $userPresent): bool
    {
        if (! $userPresent || ! $this->isEnableBanking()) {
            return $this->isRateLimited();
        }

        return $this->interactive_rate_limited_until !== null
            && $this->interactive_rate_limited_until->isFuture();
    }
}
