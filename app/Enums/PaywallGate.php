<?php

namespace App\Enums;

/**
 * Which of the paywall's screens the user is on.
 *
 * `Soft` is the only one a signup reaching the paywall today can land on: it
 * has nothing to disconnect. `FormerSubscriber` is someone who paid and
 * stopped with banks still connected, and `Hard` the users who connected a
 * bank or switched the AI on under the old rules without ever paying.
 */
enum PaywallGate: string
{
    case Soft = 'soft';

    case FormerSubscriber = 'former-subscriber';

    case Hard = 'hard';
}
