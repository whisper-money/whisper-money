<?php

namespace App\Enums;

enum ImportConfigType: string
{
    case Transaction = 'transaction';
    case Balance = 'balance';
}
