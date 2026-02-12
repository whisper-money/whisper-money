<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Laravel\Pennant\Feature;

class HandleInertiaRequests extends Middleware
{
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
        $isDemoAccount = $user?->isDemoAccount() && ! app()->environment('local') ?? false;
        $isDemoQuery = $request->query('demo') === '1';

        return [
            ...parent::share($request),
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
            'demoEncryptionKey' => $isDemoAccount ? config('app.demo.encryption_key') : null,
            'subscriptionsEnabled' => config('subscriptions.enabled', false),
            'pricing' => [
                'plans' => config('subscriptions.plans', []),
                'defaultPlan' => config('subscriptions.default_plan', 'monthly'),
                'bestValuePlan' => config('subscriptions.best_value_plan', null),
                'promo' => config('subscriptions.promo', []),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'features' => [
                'cashflow' => true,
                'plaintext-transactions' => $user ? Feature::for($user)->active('plaintext-transactions') : false,
                'open-banking' => $user ? Feature::for($user)->active('open-banking') : false,
                'account-mapping' => $user ? Feature::for($user)->active('account-mapping') : false,
            ],
            'accounts' => fn () => $user ? $user->accounts()
                ->with('bank:id,name,logo')
                ->orderBy('name')
                ->get(['id', 'name', 'name_iv', 'bank_id', 'type', 'currency_code']) : [],
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
            'hasEncryptedAccounts' => $user?->accounts()->where('encrypted', true)->exists() ?? false,
            'locale' => app()->getLocale(),
            'translations' => $this->getTranslations(),
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
