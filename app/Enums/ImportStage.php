<?php

namespace App\Enums;

/**
 * The stage a full import has reached, as the progress screen shows it.
 */
enum ImportStage: string
{
    case Upload = 'upload';
    case Queued = 'queued';
    case Wipe = 'wipe';
    case Accounts = 'accounts';
    case Categories = 'categories';
    case Transactions = 'transactions';
    case Balances = 'balances';
    case Ai = 'ai';
    case Done = 'done';
}
