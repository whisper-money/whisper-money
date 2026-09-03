<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The bell
    |--------------------------------------------------------------------------
    |
    | Whether the in-app notification centre is shown at all: the bell next to
    | the account, its panel and the /notifications page. The rows are written
    | either way, so switching it back on shows everything that happened while
    | it was off. A plain config switch rather than a Pennant flag: there is no
    | per-user rollout to run, only a kill switch to keep.
    |
    */

    'enabled' => (bool) env('NOTIFICATIONS_ENABLED', true),

];
