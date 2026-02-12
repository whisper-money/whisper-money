<?php

namespace App\Enums;

enum BankingConnectionStatus: string
{
    case Pending = 'pending';
    case AwaitingMapping = 'awaiting_mapping';
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Error = 'error';
}
