<?php

namespace App\Enums;

enum BankingConnectionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Error = 'error';
}
