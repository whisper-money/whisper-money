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

    /**
     * What a name the import cannot take as it is gets in brackets, the way
     * the wizard names the separate account for a connected namesake:
     * "Revolut (Banktrack)", "Empresa (import)".
     */
    public function nameSuffix(): string
    {
        return match ($this) {
            self::Banktrack => 'Banktrack',
            self::Generic => 'import',
        };
    }
}
