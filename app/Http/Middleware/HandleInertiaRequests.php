<?php

namespace App\Http\Middleware;

use App\Enums\AccountType;
use App\Enums\BankingConnectionStatus;
use App\Features\CalculateBalancesOnImport;
use App\Models\BankingConnection;
use App\Services\CurrencyOptions;
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
        $isDemoAccount = $user?->isDemoAccount() && ! app()->environment('local');
        $isDemoQuery = $request->query('demo') === '1';

        // Cache encryption checks to avoid duplicate queries
        $hasEncryptedAccounts = $user?->accounts()->where('encrypted', true)->exists() ?? false;
        $hasEncryptedTransactions = $user?->transactions()
            ->where(fn ($q) => $q->whereNotNull('description_iv')->orWhereNotNull('notes_iv'))
            ->exists() ?? false;

        // Clean up encryption data if no encrypted accounts or transactions remain
        if (! $request->is('api/*') && $user?->encryption_salt !== null) {
            if (! $hasEncryptedAccounts && ! $hasEncryptedTransactions) {
                $user->encryptedMessage()->delete();
                $user->update(['encryption_salt' => null]);
            }
        }

        return [
            ...parent::share($request),
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'name' => config('app.name'),
            'appUrl' => config('app.url'),
            'version' => json_decode(file_get_contents(base_path('package.json')))->version ?? '0.0.0',
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $user,
                'hasProPlan' => $user?->hasProPlan() ?? false,
                'isDemoAccount' => $isDemoAccount,
            ],
            'demoCredentials' => ($isDemoQuery || $isDemoAccount) ? [
                'email' => config('app.demo.email'),
                'password' => config('app.demo.password'),
            ] : null,
            'subscriptionsEnabled' => config('subscriptions.enabled', false),
            'pricing' => [
                'plans' => config('subscriptions.plans', []),
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
            'expiredBankingConnections' => fn () => $user ? $user->bankingConnections()
                ->where('provider', 'enablebanking')
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
                    'provider' => $connection->provider,
                    'valid_until' => $connection->valid_until?->toIso8601String(),
                    'reconnect_url' => route('open-banking.reconnect', $connection),
                ]) : [],
            'accounts' => fn () => $user ? $user->accounts()
                ->with(['bank:id,name,logo', 'realEstateDetail:account_id,linked_loan_account_id'])
                ->orderBy('name')
                ->get(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code'])
                ->map(function ($account) {
                    $data = $account->only([
                        'id',
                        'name',
                        'name_iv',
                        'encrypted',
                        'bank_id',
                        'type',
                        'currency_code',
                        'bank',
                    ]);

                    if ($account->type === AccountType::RealEstate) {
                        $data['linked_loan_account_id'] = $account->realEstateDetail?->linked_loan_account_id;
                    }

                    return $data;
                }) : [],
            'categories' => fn () => $user ? $user->categories()
                ->orderBy('name')
                ->get(['id', 'name', 'icon', 'color']) : [],
            'banks' => fn () => $user ? $user->banks()
                ->orderBy('name')
                ->get(['id', 'name', 'logo']) : [],
            'automationRules' => function () use ($user) {
                if (! $user) {
                    return [];
                }

                return $user->automationRules()
                    ->with(['category:id,name,icon,color', 'labels:id,name,color'])
                    ->orderBy('priority')
                    ->get();
            },
            'labels' => fn () => $user ? $user->labels()
                ->orderBy('name')
                ->get(['id', 'name', 'color']) : [],
            'hasEncryptedAccounts' => $hasEncryptedAccounts,
            'hasEncryptionSetup' => $user?->encryption_salt !== null,
            'hasEncryptedTransactions' => $hasEncryptedTransactions,
            'locale' => app()->getLocale(),
            'translations' => $this->getTranslations(),
            'currencies' => [
                'profile' => $this->currencyOptions->primaryOptions(),
                'accounts' => $this->currencyOptions->accountOptions(),
            ],
        ];
    }

    /**
     * @return array<string, bool>
     */
    protected function resolveFeatureFlags(): array
    {
        $user = request()->user();

        return [
            'cashflow' => true,
            'calculateBalancesOnImport' => $user
                ? Feature::for($user)->active(CalculateBalancesOnImport::class)
                : false,
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
