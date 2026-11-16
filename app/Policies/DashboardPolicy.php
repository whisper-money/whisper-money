<?php

namespace App\Policies;

use App\Features\CustomDashboards;
use App\Models\Dashboard;
use App\Models\User;
use Laravel\Pennant\Feature;

/**
 * Dashboards belong to one user and nobody else sees or edits them. This
 * instance has no plans: the CustomDashboards flag is the only gate, so a
 * user without it can neither build nor change one, nor clean one up (the
 * routes are refused with a 403; `GET /dashboard` keeps drawing the system
 * dashboard).
 */
class DashboardPolicy
{
    public function view(User $user, Dashboard $dashboard): bool
    {
        return $user->id === $dashboard->user_id;
    }

    public function create(User $user): bool
    {
        return Feature::for($user)->active(CustomDashboards::class);
    }

    public function update(User $user, Dashboard $dashboard): bool
    {
        return $this->view($user, $dashboard) && $this->create($user);
    }

    public function delete(User $user, Dashboard $dashboard): bool
    {
        return $this->update($user, $dashboard);
    }

    public function removeWidget(User $user, Dashboard $dashboard): bool
    {
        return $this->update($user, $dashboard);
    }
}
