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

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'appUrl' => config('app.url'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $user,
                'hasProPlan' => $user?->hasProPlan() ?? false,
            ],
            'subscriptionsEnabled' => config('subscriptions.enabled', false),
            'pricing' => [
                'plans' => config('subscriptions.plans', []),
                'defaultPlan' => config('subscriptions.default_plan', 'monthly'),
                'bestValuePlan' => config('subscriptions.best_value_plan', null),
                'promo' => config('subscriptions.promo', []),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'features' => [
                'budgets' => $user ? Feature::for($user)->active('budgets') : false,
            ],
        ];
    }
}
