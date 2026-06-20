<?php

use App\Models\Account;

function runAlignEncryptedFlagMigration(): void
{
    $migration = require database_path('migrations/2026_06_20_105609_align_accounts_encrypted_flag_with_plaintext_names.php');
    $migration->up();
}

it('clears the encrypted flag only for accounts whose name is plaintext', function () {
    $staleFlag = Account::factory()->create(['encrypted' => true, 'name_iv' => null]);
    $encryptedName = Account::factory()->create(['encrypted' => true, 'name_iv' => str_repeat('a', 16)]);
    $alreadyPlaintext = Account::factory()->create(['encrypted' => false, 'name_iv' => null]);

    runAlignEncryptedFlagMigration();

    expect($staleFlag->fresh()->encrypted)->toBeFalse()
        ->and($encryptedName->fresh()->encrypted)->toBeTrue()
        ->and($alreadyPlaintext->fresh()->encrypted)->toBeFalse();
});
