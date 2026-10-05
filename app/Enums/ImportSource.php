<?php

namespace App\Enums;

/**
 * The app a full import came from. It decides which column layout the wizard
 * pre-fills and which saved mapping it offers back next time.
 */
enum ImportSource: string
{
    case Banktrack = 'banktrack';
    case Generic = 'generic';

    public function label(): string
    {
        return match ($this) {
            self::Banktrack => 'Banktrack',
            self::Generic => 'Spreadsheet',
        };
    }
}
