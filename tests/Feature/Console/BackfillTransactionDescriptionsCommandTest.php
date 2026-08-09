<?php

use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\artisan;

test('reformats already-imported descriptions that still carry the remittance tag', function () {
    $transaction = Transaction::factory()->create([
        'description' => '/TXT/D|BAR CONO',
        'original_description' => null,
    ]);

    artisan('banking:backfill-descriptions')
        ->expectsOutputToContain('1 transaction(s) reformatted.')
        ->assertSuccessful();

    $transaction->refresh();

    expect($transaction->description)->toBe('BAR CONO');
    expect($transaction->original_description)->toBe('/TXT/D|BAR CONO');
});

test('leaves untagged descriptions alone', function () {
    $transaction = Transaction::factory()->create([
        'description' => 'Cafe de la Plaza',
        'original_description' => null,
    ]);

    artisan('banking:backfill-descriptions')->assertSuccessful();

    expect($transaction->refresh()->description)->toBe('Cafe de la Plaza');
});

test('keeps an original description that was already stored', function () {
    $transaction = Transaction::factory()->create([
        'description' => '/TXT/D|BAR CONO',
        'original_description' => 'SOMETHING THE USER RENAMED FROM',
    ]);

    artisan('banking:backfill-descriptions')->assertSuccessful();

    expect($transaction->refresh()->original_description)->toBe('SOMETHING THE USER RENAMED FROM');
});

test('does not save anything on a dry run', function () {
    $transaction = Transaction::factory()->create(['description' => '/TXT/D|BAR CONO']);

    artisan('banking:backfill-descriptions', ['--dry-run' => true])
        ->expectsOutputToContain('1 transaction(s) would be reformatted.')
        ->assertSuccessful();

    expect($transaction->refresh()->description)->toBe('/TXT/D|BAR CONO');
});

test('only touches the given user when one is provided', function () {
    $user = User::factory()->create();
    $mine = Transaction::factory()->for($user)->create(['description' => '/TXT/D|BAR CONO']);
    $theirs = Transaction::factory()->create(['description' => '/TXT/D|BAR CONO']);

    artisan('banking:backfill-descriptions', ['--user' => $user->email])->assertSuccessful();

    expect($mine->refresh()->description)->toBe('BAR CONO');
    expect($theirs->refresh()->description)->toBe('/TXT/D|BAR CONO');
});

test('fails when the given user does not exist', function () {
    artisan('banking:backfill-descriptions', ['--user' => 'nobody@example.com'])
        ->expectsOutputToContain("User with email 'nobody@example.com' not found.")
        ->assertFailed();
});
