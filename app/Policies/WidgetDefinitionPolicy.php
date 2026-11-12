<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WidgetDefinition;
use Illuminate\Auth\Access\Response;

/**
 * A user widget belongs to one user. To anyone else it does not exist, so
 * every check answers 404, never 403: a widget id from someone else's
 * dashboard tells nothing. Writing widgets is part of the paid plan, like
 * building dashboards.
 */
class WidgetDefinitionPolicy
{
    public function __construct(private DashboardPolicy $dashboards) {}

    /** Read it and report the errors it raised. */
    public function view(User $user, WidgetDefinition $widget): Response
    {
        return $user->id === $widget->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): Response
    {
        return $this->dashboards->create($user);
    }

    /**
     * Read its data through the gateway. Off the paid plan the widget is kept
     * but drawn as a blurred placeholder, so its data is never served.
     */
    public function readData(User $user, WidgetDefinition $widget): Response
    {
        $view = $this->view($user, $widget);

        return $view->allowed() ? $this->create($user) : $view;
    }

    /** Save a new revision, or go back to an earlier one. */
    public function update(User $user, WidgetDefinition $widget): Response
    {
        $view = $this->view($user, $widget);

        return $view->allowed() ? $this->create($user) : $view;
    }
}
