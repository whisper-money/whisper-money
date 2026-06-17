<?php

namespace App\Enums;

enum IntegrationRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case NotDoable = 'not_doable';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::NotDoable => 'Not doable',
        };
    }
}
