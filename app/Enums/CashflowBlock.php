<?php

namespace App\Enums;

/**
 * One block of the cashflow screen whose transactions the list can show: the
 * three parts expenses split into by budget expense type, and the money set
 * aside into savings or investments.
 */
enum CashflowBlock: string
{
    case Fixed = 'fixed';
    case Variable = 'variable';
    case General = 'general';
    case Saved = 'saved';
    case Invested = 'invested';
}
