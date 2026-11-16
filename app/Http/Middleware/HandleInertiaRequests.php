<?php

namespace App\Http\Middleware;

use App\Enums\AccountType;
use App\Enums\BankingProvider;
use App\Features\CustomDashboards;
use App\Features\RecurringTransactions;
use App\Features\TransactionSplitting;
use App\Jobs\PurgeResidualEncryptionArtifactsJob;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Ai\AiImportMappingGate;
use App\Services\CurrencyOptions;
use App\Services\FormatLocaleOptions;
use App\Services\Subscriptions\PriceExperiment;
use App\Services\UserCollectionVersions;
use Closure;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Inertia\OnceProp;
use Laravel\Pennant\Feature;

class HandleInertiaRequests extends Middleware
{
    /** Enough for the banner to name every bank in practice, bounded on every response. */
    private const BANK_CONNECTION_ISSUES_LIMIT = 10;

    public function __construct(
        private CurrencyOptions $currencyOptions,
        private FormatLocaleOptions $formatLocales,
        private AiImportMappingGate $aiImportMappingGate,
        private UserCollectionVersions $collectionVersions,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        // Cache encryption checks to avoid duplicate queries
        $hasEncryptedAccounts = $user?->accounts()->where('encrypted', true)->exists() ?? false;
        $hasEncryptedTransactions = $user?->transactions()
            ->where(fn ($q) => $q->whereNotNull('description_iv')->orWhereNotNull('notes_iv'))
            ->exists() ?? false;

        // A shared-data provider must stay read-only, so hand the residual
        // encryption cleanup off to a queued job instead of mutating the user
        // inline during the render. The job re-checks the condition and is
        // idempotent, so dispatching it on repeat requests is harmless.
        if ($this->hasResidualEncryptionArtifacts($request, $user, $hasEncryptedAccounts, $hasEncryptedTransactions)) {
            PurgeResidualEncryptionArtifactsJob::dispatch($user);
        }

        return [
            ...parent::share($request),
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'saved_automation_rule_id' => $request->session()->get('saved_automation_rule_id'),
                'saved_automation_rule_token' => $request->session()->get('saved_automation_rule_token'),
            ],
            'name' => config('app.name'),
            'appUrl' => config('app.url'),
            'version' => config('app.version'),
            'quote' => fn (): array => $this->inspiringQuote(),
            'auth' => [
                'user' => $user,
                'hasProPlan' => $user?->hasProPlan() ?? false,
                'isDemoAccount' => $this->isDemoAccount($user),
                'isAdmin' => $user?->isAdmin() ?? false,
            ],
            'subscriptionPaymentIssue' => $user?->hasPastDueSubscription() ? [
                'status' => 'past_due',
                'action_url' => route('settings.billing.portal'),
            ] : null,
            'demoEnabled' => (bool) config('app.demo.enabled'),
            'openBankingEnabled' => (bool) config('services.enablebanking.enabled'),
            'betaProviders' => BankingProvider::betaApiKeyProviders(),
            'demoCredentials' => $this->demoCredentials($request, $user),
            'subscriptionsEnabled' => config('subscriptions.enabled', false),
            'aiCategorizationUpsellRate' => (int) config('ai_categorization.upsell_sample_rate'),
            'aiImportMappingEnabled' => $user !== null && $this->aiImportMappingGate->allows($user),
            'pricing' => [
                'plans' => PriceExperiment::plansFor($user, $request->cookie(PriceExperiment::COOKIE)),
                'defaultPlan' => config('subscriptions.default_plan', 'monthly'),
                'bestValuePlan' => config('subscriptions.best_value_plan', null),
                'promo' => config('subscriptions.promo', []),
                'currency' => strtoupper(config('cashier.currency', 'eur')),
            ],
            'chartColorScheme' => $user?->setting?->chart_color_scheme->value ?? 'colorful',
            'includeLoansInNetWorthChart' => $user?->setting->include_loans_in_net_worth_chart ?? false,
            'includeRealEstateInNetWorthChart' => $user?->setting->include_real_estate_in_net_worth_chart ?? false,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'features' => $this->resolveFeatureFlags(),
            ...$this->userCollectionProps($user),
            'hasEncryptedAccounts' => $hasEncryptedAccounts,
            'hasEncryptionSetup' => $user?->encryption_salt !== null,
            'hasEncryptedTransactions' => $hasEncryptedTransactions,
            // Resolved once and kept by the client for an hour, so the aggregate
            // runs on a full page load rather than on every visit. Keyed by the
            // user: the client keeps once props across a login, and the guest's
            // `false` from the login page would otherwise hide the button for
            // the hour that follows.
            'showHeaderAddTransaction' => Inertia::once(fn (): bool => $this->showsHeaderAddTransaction($user))
                ->as('showHeaderAddTransaction:'.($user->id ?? 'guest'))
                ->until(now()->addHour()),
            'locale' => $this->formatLocaleFor($request, $user),
            // The dictionary is ~250 KB in es/fr: a once prop keyed by the
            // locale and the file's mtime, so it ships again only when either
            // changes. See translationsProp().
            'translations' => fn (): OnceProp => $this->translationsProp(),
            'currencies' => [
                'profile' => $this->currencyOptions->primaryOptions(),
                'accounts' => $this->currencyOptions->accountOptions(),
            ],
            // Codes only: the picker writes each one's country name and its
            // live example with `Intl`, the way `useConnectCountries()` already
            // does, so 43 country names never need a translation entry — and
            // the list never exists twice, once in PHP and once in TypeScript.
            'formatLocales' => $this->formatLocales->codes(),
        ];
    }

    /**
     * The props that list the user's own records. Guests get empty lists so the
     * frontend can read them unconditionally.
     *
     * The banking connections stay plain closures: they are small, and which
     * of them have expired moves with the clock rather than with a write.
     *
     * @return array<string, Closure>
     */
    private function userCollectionProps(?User $user): array
    {
        if ($user === null) {
            return array_fill_keys([
                'bankConnectionIssues',
                'bankingConnections',
                'accounts',
                'categories',
                'banks',
                'automationRules',
                'labels',
            ], fn () => []);
        }

        return [
            'bankConnectionIssues' => fn (): array => $this->bankConnectionIssuesFor($user),
            'bankingConnections' => fn () => $user->bankingConnections()
                ->get(['id', 'aspsp_name', 'provider', 'status'])
                ->map(fn (BankingConnection $connection): array => [
                    'id' => $connection->id,
                    'aspsp_name' => $connection->aspsp_name,
                    'provider' => $connection->provider->value,
                    'status' => $connection->status->value,
                ])
                ->all(),
            ...$this->versionedCollectionProps($user, [
                'accounts' => fn () => $user->accounts()
                    ->with(['bank', 'realEstateDetail:id,account_id,linked_loan_account_id'])
                    ->orderBy('name')
                    ->get()
                    ->makeHidden('realEstateDetail'),
                'categories' => fn () => $user->categories()
                    ->forDisplay()
                    ->get(),
                'banks' => fn () => $user->banks()
                    ->orderBy('name')
                    ->get(),
                'automationRules' => fn () => $user->automationRules()
                    ->with(['category', 'labels'])
                    ->orderBy('priority')
                    ->get(),
                'labels' => fn () => $user->labels()
                    ->orderBy('name')
                    ->get(),
            ]),
        ];
    }

    /**
     * The connections that stopped syncing until the user reconnects them, for
     * the app-wide banner. An Enable Banking connection carries the URL that
     * restarts its consent at the bank; one through an API-key provider is fixed
     * by updating its credentials on the connections page, so it carries none.
     *
     * Only the user's own connections, as on the connections page: a shared
     * space's member cannot reconnect another member's bank. One indexed query,
     * bounded, on every full page response.
     *
     * @return list<array{id: string, aspsp_name: string, provider: string, reason: 'expired'|'error', reconnect_url: string|null}>
     */
    private function bankConnectionIssuesFor(User $user): array
    {
        return $user->bankingConnections()
            ->needingReconnect()
            ->orderBy('aspsp_name')
            ->limit(self::BANK_CONNECTION_ISSUES_LIMIT)
            ->get(['id', 'aspsp_name', 'provider', 'status', 'valid_until'])
            ->map(fn (BankingConnection $connection): array => [
                'id' => $connection->id,
                'aspsp_name' => $connection->aspsp_name,
                'provider' => $connection->provider->value,
                'reason' => $connection->isExpired() ? 'expired' : 'error',
                'reconnect_url' => $connection->isEnableBanking()
                    ? route('open-banking.reconnect', $connection)
                    : null,
            ])
            ->all();
    }

    /**
     * Wrap each list as a once prop the client keeps between visits, keyed by
     * the user and a fingerprint of the rows behind it. Any write moves the
     * fingerprint, so the next response carries a key the client does not hold
     * and the list comes down again — after a redirect, a reload or a plain
     * navigation alike — while a visit that changed nothing skips the queries
     * and the payload. A partial reload that names the prop always resolves it.
     *
     * The key is worked out only when a response is built (one query for all
     * five), not on the JSON endpoints that run this middleware too. The hour
     * is a backstop for a write the fingerprint cannot see, such as a second
     * edit within the same second as the read before it.
     *
     * @param  array<string, Closure>  $collections
     * @return array<string, Closure>
     */
    private function versionedCollectionProps(User $user, array $collections): array
    {
        $versions = null;
        $props = [];

        foreach ($collections as $prop => $resolve) {
            $props[$prop] = function () use ($user, $prop, $resolve, &$versions): OnceProp {
                $versions ??= $this->collectionVersions->for($user);

                return Inertia::once($resolve)
                    ->as("{$prop}:{$user->id}:{$versions[$prop]}")
                    ->until(now()->addHour());
            };
        }

        return $props;
    }

    /**
     * Whether the header offers its own add-transaction button: only to the
     * onboarded readers who add a meaningful share of their transactions by
     * hand, and who still own an account to file one in. Everyone else reaches
     * the dialog from the transactions list. The account check runs last, so
     * only the readers who qualify pay for it.
     */
    private function showsHeaderAddTransaction(?User $user): bool
    {
        if ($user === null || ! $user->isOnboarded() || ! $user->addsTransactionsByHand()) {
            return false;
        }

        return $user->accounts()
            ->whereNull('archived_at')
            ->whereIn('type', AccountType::withTransactionLedger())
            ->exists();
    }

    /**
     * The demo account is only treated as one outside local, where a developer
     * signed in as it should get the normal app.
     */
    private function isDemoAccount(?User $user): bool
    {
        return ($user?->isDemoAccount() ?? false) && ! app()->environment('local');
    }

    /**
     * The demo login is prefilled for whoever asked for it (?demo=1) and for the
     * demo account itself, so it can sign back in after logging out.
     *
     * @return array{email: ?string, password: ?string}|null
     */
    private function demoCredentials(Request $request, ?User $user): ?array
    {
        $wantsDemo = $request->query('demo') === '1' || $this->isDemoAccount($user);

        if (! config('app.demo.enabled') || ! $wantsDemo) {
            return null;
        }

        return [
            'email' => config('app.demo.email'),
            'password' => config('app.demo.password'),
        ];
    }

    /**
     * True when the user finished decrypting their data but the encryption salt
     * and other artifacts are still on the row, so the cleanup job has work.
     * Skipped for API requests, which are not a render path.
     */
    private function hasResidualEncryptionArtifacts(Request $request, ?User $user, bool $hasEncryptedAccounts, bool $hasEncryptedTransactions): bool
    {
        return ! $request->is('api/*')
            && $user?->encryption_salt !== null
            && ! $hasEncryptedAccounts
            && ! $hasEncryptedTransactions;
    }

    /**
     * @return array<string, bool>
     */
    protected function resolveFeatureFlags(): array
    {
        $user = request()->user();

        if (! $user) {
            return [
                'cashflow' => true,
                'transactionSplitting' => true,
                'recurringTransactions' => false,
                'fullImport' => false,
                'fullImportSettings' => false,
                'customDashboards' => false,
            ];
        }

        // One query for every flag, however many of them get read below.
        $features = Feature::for($user)->values([
            TransactionSplitting::class,
            RecurringTransactions::class,
            CustomDashboards::class,
        ]);

        return [
            'cashflow' => true,
            'transactionSplitting' => $features[TransactionSplitting::class] !== false,
            'recurringTransactions' => $features[RecurringTransactions::class] !== false,
            // Two answers, because Settings keeps the page past the window for
            // as long as an import can still be undone, while starting one is
            // only offered inside it. The second costs a query once the window
            // is closed, and only the Settings menu reads it, so only Settings
            // pages pay for it.
            'fullImport' => $user->canUseFullImport(),
            'fullImportSettings' => request()->is('settings', 'settings/*') && $user->canSeeFullImportSettings(),
            'customDashboards' => $features[CustomDashboards::class] !== false,
        ];
    }

    /**
     * The locale the client writes amounts and dates in — a full region like
     * `es-MX`, and deliberately NOT `app()->getLocale()`, which this prop used
     * to carry.
     *
     * The two diverge on purpose and both are right. `app()->getLocale()` names
     * the language `lang/` is translated into and has to stay two letters, or
     * `App::setLocale('es-MX')` sends the translator after a directory that does
     * not exist. This prop names the region, which is what decides where the
     * decimal separator goes and how a date is ordered — and a reader is allowed
     * to want the app in English with Mexican numbers.
     *
     * Nothing is lost by the swap: `i18n.ts` translates off the `translations`
     * prop and never reads this one. See `use-locale.ts`.
     */
    private function formatLocaleFor(Request $request, ?User $user): string
    {
        // A signed-out visitor has no row to have detected anything onto, so
        // the header is read for them on every request instead.
        return $user?->formatLocale()
            ?? $this->formatLocales->detectFromHeader($request->header('Accept-Language'), app()->getLocale());
    }

    /**
     * Every translation for the current locale, as a once prop: the file runs
     * to hundreds of kilobytes, and the client keeps it between visits instead
     * of being sent it again on each one. Keyed by the locale and the file's
     * modification time, so switching language or deploying new copy sends it
     * down again; the file is only read and decoded when it is. Shared behind
     * a closure, so the JSON endpoints this middleware also runs on never stat
     * the file.
     */
    private function translationsProp(): OnceProp
    {
        $locale = app()->getLocale();
        $translationFile = lang_path("{$locale}.json");
        $modifiedAt = file_exists($translationFile) ? filemtime($translationFile) : 0;

        return Inertia::once(fn (): array => $this->getTranslations($translationFile))
            ->as("translations:{$locale}:{$modifiedAt}");
    }

    /**
     * Get all translations in a locale file as a flat key-value array.
     *
     * @return array<string, string>
     */
    protected function getTranslations(string $translationFile): array
    {
        if (! file_exists($translationFile)) {
            return [];
        }

        return json_decode(file_get_contents($translationFile), true) ?? [];
    }

    /**
     * A random inspiring quote for the split auth layout.
     *
     * @return array{message: string, author: string}
     */
    private function inspiringQuote(): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return ['message' => trim($message), 'author' => trim($author)];
    }
}
