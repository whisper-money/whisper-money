<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Some legacy accounts are still flagged `encrypted = true` even though
     * their name is stored in plaintext (`name_iv` is null). The client-side
     * decrypt-migration only clears the flag for accounts whose name is
     * actually encrypted, so these stale flags would never resolve on their
     * own and keep the user perpetually counted as "has encrypted accounts".
     * Align the flag with reality: an account with no `name_iv` is not
     * encrypted. Transaction decryption is unaffected — it is driven by each
     * transaction's `description_iv` / `notes_iv`, not by this flag.
     */
    public function up(): void
    {
        DB::table('accounts')
            ->where('encrypted', true)
            ->whereNull('name_iv')
            ->update(['encrypted' => false]);
    }

    /**
     * Irreversible: once flipped, a migration-corrected account is
     * indistinguishable from an account that was always plaintext, so blanket
     * re-flagging would wrongly encrypt legitimately unencrypted accounts.
     */
    public function down(): void
    {
        //
    }
};
