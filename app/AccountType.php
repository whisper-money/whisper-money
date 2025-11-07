<?php

namespace App\Enums;

enum AccountType: string
{
    case Checking = 'checking';
    case CreditCard = 'credit_card';
    case Loan = 'loan';
    case Savings = 'savings';
    case Others = 'others';
}
