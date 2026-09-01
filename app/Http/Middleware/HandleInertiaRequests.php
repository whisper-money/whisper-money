<?php

namespace App\Http\Middleware;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Features\CalculateBalancesOnImport;
use App\Features\SplitTransactions;
use App\Jobs\PurgeResidualEncryptionArtifactsJob;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\CurrencyOptions;
use App\Services\Subscriptions\PriceExperiment;
use Closure;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Laravel\Pennant\Feature;

class HandleInertiaRequests extends Middleware
{
    public function __construct(private CurrencyOptions $currencyOptions) {}

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
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

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
            'version' => json_decode(file_get_contents(base_path('package.json')))->version ?? '0.0.0',
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $user,
                'hasProPlan' => $user?->hasProPlan() ?? false,
                'isDemoAccount' => $user?->isRestrictedDemoAccount() ?? false,
                'isSharedAccount' => $user?->isRestrictedSharedAccount() ?? false,
            ],
            'subscriptionPaymentIssue' => $user?->hasPastDueSubscription() ? [
                'status' => 'past_due',
                'action_url' => route('settings.billing.portal'),
            ] : null,
            'demoEnabled' => (bool) config('app.demo.enabled'),
            'demoCredentials' => $this->demoCredentials($request, $user),
            'subscriptionsEnabled' => config('subscriptions.enabled', false),
            'aiCategorizationUpsellRate' => (int) config('ai_categorization.upsell_sample_rate'),
            'pricing' => [
                'plans' => PriceExperiment::plansFor($user, $request->cookie(PriceExperiment::COOKIE)),
                'defaultPlan' => config('subscriptions.default_plan', 'monthly'),
                'bestValuePlan' => config('subscriptions.best_value_plan', null),
                'promo' => config('subscriptions.promo', []),
                'currency' => strtoupper(config('cashier.currency', 'eur')),
            ],
            'chartColorScheme' => $user?->setting?->chart_color_scheme->value ?? 'colorful',
            'includeLoansInNetWorthChart' => $user?->setting->include_loans_in_net_worth_chart ?? true,
            'includeRealEstateInNetWorthChart' => $user?->setting->include_real_estate_in_net_worth_chart ?? true,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'features' => $this->resolveFeatureFlags(),
            ...$this->userCollectionProps($user),
            'hasEncryptedAccounts' => $hasEncryptedAccounts,
            'hasEncryptionSetup' => $user?->encryption_salt !== null,
            'hasEncryptedTransactions' => $hasEncryptedTransactions,
            'locale' => app()->getLocale(),
            'translations' => $this->getTranslations(),
            'currencies' => [
                'profile' => $this->currencyOptions->primaryOptions(),
                'accounts' => $this->currencyOptions->accountOptions(),
                // Keyed by code rather than folded into the two lists above:
                // a transaction can be in a currency no longer offered, and it
                // still has to render at the right scale.
                'decimals' => $this->currencyOptions->decimalsMap(),
            ],
        ];
    }

    /**
     * The deferred props that list the user's own records. Guests get empty lists
     * so the frontend can read them unconditionally.
     *
     * @return array<string, Closure>
     */
    private function userCollectionProps(?User $user): array
    {
        if ($user === null) {
            return array_fill_keys([
                'expiredBankingConnections',
                'bankingConnections',
                'accounts',
                'categories',
                'banks',
                'automationRules',
                'labels',
            ], fn () => []);
        }

        return [
            'expiredBankingConnections' => fn () => $user->bankingConnections()
                ->where('provider', BankingProvider::EnableBanking)
                ->where(function ($query) {
                    $query->where('status', BankingConnectionStatus::Expired)
                        ->orWhere(function ($query) {
                            $query->whereNotNull('valid_until')
                                ->where('valid_until', '<=', now());
                        });
                })
                ->orderBy('valid_until')
                ->limit(5)
                ->get(['id', 'aspsp_name', 'provider', 'valid_until'])
                ->map(fn (BankingConnection $connection): array => [
                    'id' => $connection->id,
                    'aspsp_name' => $connection->aspsp_name,
                    'provider' => $connection->provider->value,
                    'valid_until' => $connection->valid_until?->toIso8601String(),
                    'reconnect_url' => route('open-banking.reconnect', $connection),
                ])
                ->all(),
            'bankingConnections' => fn () => $user->bankingConnections()
                ->get(['id', 'aspsp_name', 'provider', 'status'])
                ->map(fn (BankingConnection $connection): array => [
                    'id' => $connection->id,
                    'aspsp_name' => $connection->aspsp_name,
                    'provider' => $connection->provider->value,
                    'status' => $connection->status->value,
                ])
                ->all(),
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
        ];
    }

    /**
     * The demo login is prefilled for whoever asked for it (?demo=1) and for the
     * demo account itself, so it can sign back in after logging out.
     *
     * @return array{email: ?string, password: ?string}|null
     */
    private function demoCredentials(Request $request, ?User $user): ?array
    {
        $wantsDemo = $request->query('demo') === '1' || ($user?->isRestrictedDemoAccount() ?? false);

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
                'calculateBalancesOnImport' => false,
                'splitTransactions' => false,
            ];
        }

        $features = Feature::for($user)->values([
            CalculateBalancesOnImport::class,
            SplitTransactions::class,
        ]);

        return [
            'cashflow' => true,
            'calculateBalancesOnImport' => $features[CalculateBalancesOnImport::class] !== false,
            'splitTransactions' => $features[SplitTransactions::class] !== false,
        ];
    }

    /**
     * Get all translations for the current locale as a flat key-value array.
     *
     * @return array<string, string>
     */
    protected function getTranslations(): array
    {
        $locale = app()->getLocale();
        $translationFile = lang_path("{$locale}.json");

        if (! file_exists($translationFile)) {
            return [];
        }

        return json_decode(file_get_contents($translationFile), true) ?? [];
    }
}
