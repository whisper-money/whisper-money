<?php

namespace App\Enums;

/**
 * How an `/api/v1` request authenticated: a personal access token (Sanctum)
 * or an OAuth access token issued to a third-party app (Passport). `dashboard`
 * marks the calls the widget gateway makes for a dashboard's widgets with the
 * web session: never a credential the API accepts, only a label for usage.
 */
enum ApiCredential: string
{
    case PersonalAccessToken = 'pat';
    case OAuth = 'oauth';
    case Dashboard = 'dashboard';
}
