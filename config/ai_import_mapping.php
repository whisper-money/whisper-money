<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | A hard kill switch for AI column-mapping suggestions on CSV/XLSX imports.
    | When false, no file layout is ever sent to TypeSafe AI's Jev. It also
    | needs TYPESAFE_API_KEY: without a key the suggestions stay off.
    |
    */

    'enabled' => (bool) env('AI_IMPORT_MAPPING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Free users
    |--------------------------------------------------------------------------
    |
    | Whether users without a pro plan get suggestions too. Only each column's
    | header and a computed shape (e.g. "date (dd/mm/yyyy)") are sent, never a
    | cell value, so no AI consent is required either way.
    |
    */

    'free_users_enabled' => (bool) env('AI_IMPORT_MAPPING_FREE_USERS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Confidence bar
    |--------------------------------------------------------------------------
    |
    | Minimum confidence (0-1) for a suggested column to be returned. A field
    | answered below it comes back unmapped, leaving the choice to the user.
    |
    */

    'min_confidence' => (float) env('AI_IMPORT_MAPPING_MIN_CONFIDENCE', 0.5),

];
