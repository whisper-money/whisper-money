<?php

namespace App\Jobs;

use App\Console\Commands\Concerns\FindsUsersWithLegacyEncryption;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
 * It is also {@see ShouldBeUnique} keyed by user, so the repeated dispatches that
 * share() issues on every page load collapse into a single queued job per user.
 *
 * Because the purge is destructive (it drops the only key material for any data
 * still encrypted at rest), the residual check uses the same source of truth as
 * {@see FindsUsersWithLegacyEncryption}: the per-row `*_iv` columns, never the
 * stale `accounts.encrypted` flag. Keying off that flag could destroy the salt
 * while an account name is still encrypted.
 */
class PurgeResidualEncryptionArtifactsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public User $user) {}

    public function uniqueId(): string
    {
        return $this->user->id;
    }

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
            ->whereNotNull('name_iv')
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
