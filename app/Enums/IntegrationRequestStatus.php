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

    /**
     * Marking a request not doable must tell users why.
     */
    public function requiresComment(): bool
    {
        return $this === self::NotDoable;
    }

    /**
     * Whether a public comment survives a move to this status. Approving,
     * rejecting, re-queuing or marking done drops any stale one.
     */
    public function keepsComment(): bool
    {
        return in_array($this, [self::NotDoable, self::InProgress], true);
    }
}
