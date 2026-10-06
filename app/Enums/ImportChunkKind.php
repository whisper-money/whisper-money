<?php

namespace App\Enums;

/**
 * The rows a staged chunk of a full import carries.
 */
enum ImportChunkKind: string
{
    case Transactions = 'transactions';
    case Balances = 'balances';
}
