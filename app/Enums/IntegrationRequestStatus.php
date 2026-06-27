<?php

namespace App\Enums;

enum IntegrationRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case InProgress = 'in_progress';
    case Rejected = 'rejected';
    case NotDoable = 'not_doable';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::InProgress => 'In progress',
            self::Rejected => 'Rejected',
            self::NotDoable => 'Not doable',
            self::Done => 'Done',
        };
    }
}
