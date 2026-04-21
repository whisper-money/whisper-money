<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hide Authentication Buttons
    |--------------------------------------------------------------------------
    |
    | When set to true, this will hide authentication buttons (login/register)
    | from the landing page and block registration unless a valid override is
    | present.
    |
    */

    'hide_auth_buttons' => env('HIDE_AUTH_BUTTONS', false),

    /*
    |--------------------------------------------------------------------------
    | Authentication Override
    |--------------------------------------------------------------------------
    |
    | Temporary signed landing links can unlock authentication buttons for a
    | limited time. Once a user opens a valid signed link, a secure cookie is
    | stored so the same browser session can continue into the installed PWA.
    |
    */

    'auth_override' => [
        'query_parameter' => 'signup',
        'cookie_name' => 'landing_auth_override',
        'cookie_minutes' => 60 * 24 * 7,
        'ignore_signature_query_parameters' => [
            'lang',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'gclid',
            'fbclid',
        ],
    ],

];
