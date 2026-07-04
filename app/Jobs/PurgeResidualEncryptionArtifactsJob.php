<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Once a user has decrypted (or removed) every client-side encrypted account and
 * transaction, the leftover encryption salt and EncryptedMessage row serve no
 * purpose. This job clears them so `hasEncryptionSetup` stops reporting true.
 *
 * It re-checks the condition on execution and is therefore idempotent: dispatching
 * it more than once (or after the salt was already cleared elsewhere) is a no-op.
 */
class PurgeResidualEncryptionArtifactsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public User $user) {}

    public function handle(): void
    {
        $user = $this->user->fresh();

        if ($user === null || $user->encryption_salt === null) {
            return;
        }

        if ($this->hasResidualEncryptedData($user)) {
            return;
        }

        $user->encryptedMessage()->delete();
        $user->update(['encryption_salt' => null]);
    }

    private function hasResidualEncryptedData(User $user): bool
    {
        $hasEncryptedAccounts = $user->accounts()
            ->where('encrypted', true)
            ->exists();

        if ($hasEncryptedAccounts) {
            return true;
        }

        return $user->transactions()
            ->where(function (Builder $query): void {
                $query->whereNotNull('description_iv')
                    ->orWhereNotNull('notes_iv');
            })
            ->exists();
    }
}
