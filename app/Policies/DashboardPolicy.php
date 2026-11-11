<?php

namespace App\Policies;

use App\Models\Dashboard;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Dashboards belong to one user and nobody else sees or edits them. Building
 * them is part of the paid plan: a free user who gets past `subscribed` can
 * still open the app, but cannot create or change a dashboard. Deleting stays
 * open to the owner, so a user who left the plan can still clean up.
 */
class DashboardPolicy
{
    public function view(User $user, Dashboard $dashboard): bool
    {
        return $user->id === $dashboard->user_id;
    }

    public function create(User $user): Response
    {
        return $user->hasProPlan()
            ? Response::allow()
            : Response::deny(__('Custom dashboards are part of the Standard plan.'));
    }

    public function update(User $user, Dashboard $dashboard): Response
    {
        if (! $this->view($user, $dashboard)) {
            return Response::deny();
        }

        return $this->create($user);
    }

    public function delete(User $user, Dashboard $dashboard): bool
    {
        return $this->view($user, $dashboard);
    }

    /** Removing a widget is cleanup too, open to the owner without a plan. */
    public function removeWidget(User $user, Dashboard $dashboard): bool
    {
        return $this->view($user, $dashboard);
    }
}
