<?php

namespace App\Enums;

enum BankingSyncLogStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
