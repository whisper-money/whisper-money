<?php

namespace App\Enums;

enum AccountType: string
{
    case Checking = 'checking';
    case CreditCard = 'credit_card';
    case Investment = 'investment';
    case Loan = 'loan';
    case Retirement = 'retirement';
    case Savings = 'savings';
    case Others = 'others';
}
