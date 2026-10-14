<?php

namespace App\Enums;

enum SavingsGoalKind: string
{
    case OneOff = 'one_off';
    case Monthly = 'monthly';
}
