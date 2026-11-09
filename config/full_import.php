<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Turns the full import from another app off for everyone without a
    | deploy. When false, nobody can start one: nobody sees the onboarding
    | row, the Settings entry or its CTA, and the endpoints refuse new
    | imports. Imports already running finish, and the history and undo of
    | past imports stay available. Read by `User::canUseFullImport()`.
    |
    */

    'enabled' => (bool) env('FULL_IMPORT_ENABLED', true),

];
