<?php

namespace App\Policies;

use App\Features\CustomDashboards;
use App\Models\User;
use App\Models\WidgetDefinition;
use Illuminate\Auth\Access\Response;
use Laravel\Pennant\Feature;

/**
 * A user widget belongs to one user. To anyone else it does not exist, so a
 * widget that is not theirs answers 404, never 403: a widget id from someone
 * else's dashboard tells nothing. This instance has no plans: like the
 * dashboards ({@see DashboardPolicy}), the CustomDashboards flag is the only
 * gate, so its owner without the flag can neither read nor change it.
 */
class WidgetDefinitionPolicy
{
    /** Read it, its data, and report the errors it raised. */
    public function view(User $user, WidgetDefinition $widget): Response
    {
        if ($user->id !== $widget->user_id) {
            return Response::denyAsNotFound();
        }

        return $this->create($user);
    }

    public function create(User $user): Response
    {
        return Feature::for($user)->active(CustomDashboards::class) ? Response::allow() : Response::deny();
    }

    /** Save a new revision, or go back to an earlier one. */
    public function update(User $user, WidgetDefinition $widget): Response
    {
        return $this->view($user, $widget);
    }
}
