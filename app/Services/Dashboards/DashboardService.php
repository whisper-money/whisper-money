<?php

namespace App\Services\Dashboards;

use App\Enums\WidgetOrigin;
use App\Models\Dashboard;
use App\Models\User;
use App\Policies\DashboardPolicy;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Creates the user's own dashboards, from the web app and through MCP
 * (create_dashboard). Who may create one is the callers' job
 * ({@see DashboardPolicy}).
 */
class DashboardService
{
    /**
     * A new dashboard named $name, after the user's others, created from $origin.
     *
     * @throws ValidationException when the user already has Dashboard::MAX_PER_USER
     */
    public function create(User $user, string $name, WidgetOrigin $origin): Dashboard
    {
        if ($user->dashboards()->count() >= Dashboard::MAX_PER_USER) {
            throw ValidationException::withMessages([
                'name' => __('You can have up to :count dashboards.', ['count' => Dashboard::MAX_PER_USER]),
            ]);
        }

        $dashboard = $user->dashboards()->create([
            'name' => $name,
            'position' => (int) $user->dashboards()->max('position') + 1,
        ]);

        // A count for the feature's metrics: where it came from, never its name.
        Log::info('Custom dashboard created', ['source' => $origin->value]);

        return $dashboard;
    }
}
