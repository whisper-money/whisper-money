<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Seats per subscription
    |--------------------------------------------------------------------------
    |
    | The Business plan includes this many unique users (the owner plus invited
    | members and still-pending invitations, counted across all of the owner's
    | spaces). At launch this is a hard cap; per-seat billing beyond it is a
    | later addition.
    |
    */

    'max_seats' => (int) env('SPACES_MAX_SEATS', 5),

    /*
    |--------------------------------------------------------------------------
    | Invitation expiry
    |--------------------------------------------------------------------------
    */

    'invitation_expiry_days' => (int) env('SPACES_INVITATION_EXPIRY_DAYS', 14),

];
