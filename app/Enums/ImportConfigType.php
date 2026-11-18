<?php

namespace App\Enums;

enum ImportConfigType: string
{
    case Transaction = 'transaction';
    case Balance = 'balance';
    /** Which accounts a broker file went to last time, and what it was worth. */
    case Broker = 'broker';

    /**
     * The kinds a file's columns are mapped for: a broker file has its own
     * reader and no column mapping.
     *
     * @return list<self>
     */
    public static function columnMapped(): array
    {
        return [self::Transaction, self::Balance];
    }
}
