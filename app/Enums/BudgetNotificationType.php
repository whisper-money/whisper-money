<?php

namespace App\Enums;

enum BudgetNotificationType: string
{
    case NewTransaction = 'new_transaction';
    case CloseToLimit = 'close_to_limit';
    case OverLimit = 'over_limit';
}
