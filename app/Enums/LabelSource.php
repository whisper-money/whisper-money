<?php

namespace App\Enums;

enum LabelSource: string
{
    case User = 'user';
    case SavingsGoal = 'saving_goal';
    /** The "Estimate" label on the rough monthly expenses typed in during onboarding. */
    case Estimate = 'estimate';
}
