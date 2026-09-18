<?php

namespace App\Enums;

/**
 * Why an address stopped being mailable. Both reasons come from SES feedback
 * and both are permanent as far as we are concerned: a hard bounce means the
 * mailbox does not exist, a complaint means the reader pressed "spam". Either
 * one keeps counting against the account reputation every time we send again.
 */
enum SuppressionReason: string
{
    case Bounce = 'bounce';
    case Complaint = 'complaint';
}
