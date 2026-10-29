<?php

namespace App\Enums;

/**
 * How an `/api/v1` request authenticated: a personal access token (Sanctum)
 * or an OAuth access token issued to a third-party app (Passport).
 */
enum ApiCredential: string
{
    case PersonalAccessToken = 'pat';
    case OAuth = 'oauth';
}
