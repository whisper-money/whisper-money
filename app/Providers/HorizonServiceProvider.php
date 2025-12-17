<?php

namespace App\Providers;

use App\Http\Middleware\HorizonBasicAuth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $username = config('horizon.basic_auth_username');
        $password = config('horizon.basic_auth_password');

        if (! empty($username) && ! empty($password)) {
            $middleware = config('horizon.middleware', ['web']);
            $middleware[] = HorizonBasicAuth::class;
            Config::set('horizon.middleware', $middleware);
        }

        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return in_array(optional($user)->email, [
                //
            ]);
        });
    }
}
