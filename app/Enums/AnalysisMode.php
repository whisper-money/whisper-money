<?php

namespace App\Enums;

enum AnalysisMode: string
{
    case Expense = 'expense';
    case Income = 'income';
    case Trend = 'trend';
}
