<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hide Authentication Buttons
    |--------------------------------------------------------------------------
    |
    | When set to true, this will hide authentication buttons (login/register)
    | from the landing page and return 404 for auth routes.
    |
    */

    'hide_auth_buttons' => env('HIDE_AUTH_BUTTONS', false),

    /*
    |--------------------------------------------------------------------------
    | Lead Redirect URL
    |--------------------------------------------------------------------------
    |
    | The external URL to redirect users to after they submit their email
    | on the landing page.
    |
    */

    'lead_redirect_url' => env('LEAD_REDIRECT_URL', null),

];
