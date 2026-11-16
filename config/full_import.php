<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | The only switch for the full import from another app. Off by default on
    | this instance, and then nobody can start one: nobody sees the Settings
    | entry or its CTA, and the endpoints refuse new imports. On
    | (`FULL_IMPORT_ENABLED=true`), it opens to each user while still
    | onboarding and for `User::FULL_IMPORT_WINDOW_DAYS` days after it, never
    | later. Imports already running finish, and the history and undo of past
    | imports stay available either way. Read by `User::canUseFullImport()`.
    |
    */

    'enabled' => (bool) env('FULL_IMPORT_ENABLED', false),

];
