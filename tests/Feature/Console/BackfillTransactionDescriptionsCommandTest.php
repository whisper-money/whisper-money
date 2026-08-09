<?php

use App\Models\AutomationRule;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\artisan;

function taggedTransaction(array $attributes = []): Transaction
{
    return Transaction::factory()->create([
        'description' => '/TXT/D|BAR CONO',
        'original_description' => null,
        'description_iv' => null,
        ...$attributes,
    ]);
}

test('reformats already-imported descriptions that still carry the remittance tag', function () {
    $transaction = taggedTransaction();

    artisan('banking:backfill-descriptions')
        ->expectsOutputToContain('1 transaction(s) and 0 automation rule(s) reformatted.')
        ->assertSuccessful();

    $transaction->refresh();

    expect($transaction->description)->toBe('BAR CONO');
    expect($transaction->original_description)->toBe('/TXT/D|BAR CONO');
});

test('leaves untagged descriptions alone', function () {
    $transaction = taggedTransaction(['description' => 'Cafe de la Plaza']);

    artisan('banking:backfill-descriptions')->assertSuccessful();

    expect($transaction->refresh()->description)->toBe('Cafe de la Plaza');
});

test('skips transactions the server cannot read', function () {
    $transaction = taggedTransaction(['description_iv' => 'abcdefghijklmnop']);

    artisan('banking:backfill-descriptions')->assertSuccessful();

    expect($transaction->refresh()->description)->toBe('/TXT/D|BAR CONO');
});

test('keeps an original description that was already stored', function () {
    $transaction = taggedTransaction(['original_description' => '/TXT/D|BAR CONO  27/07/26|20260803']);

    artisan('banking:backfill-descriptions')->assertSuccessful();

    expect($transaction->refresh()->original_description)->toBe('/TXT/D|BAR CONO  27/07/26|20260803');
});

test('rewrites the automation rules that match on the raw tag', function () {
    $rule = AutomationRule::factory()->for(User::factory())->create([
        'rules_json' => ['in' => ['/TXT/D|BAR CONO', ['var' => 'description']]],
    ]);

    artisan('banking:backfill-descriptions')
        ->expectsOutputToContain('0 transaction(s) and 1 automation rule(s) reformatted.')
        ->assertSuccessful();

    expect($rule->refresh()->rules_json)->toBe(['in' => ['BAR CONO', ['var' => 'description']]]);
});

test('rewrites the tag in rules stored as a JSON string', function () {
    $rule = AutomationRule::factory()->for(User::factory())->create([
        'rules_json' => json_encode(['in' => ['/TXT/D|BAR CONO', ['var' => 'description']]]),
    ]);

    artisan('banking:backfill-descriptions')->assertSuccessful();

    expect(json_decode($rule->refresh()->rules_json, true))
        ->toBe(['in' => ['BAR CONO', ['var' => 'description']]]);
});

test('leaves untagged automation rules untouched', function () {
    $rule = AutomationRule::factory()->for(User::factory())->create([
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
    ]);

    artisan('banking:backfill-descriptions')->assertSuccessful();

    expect($rule->refresh()->rules_json)->toBe(['in' => ['grocery', ['var' => 'description']]]);
});

test('does not save anything on a dry run', function () {
    $transaction = taggedTransaction();
    $rule = AutomationRule::factory()->for(User::factory())->create([
        'rules_json' => ['in' => ['/TXT/D|BAR CONO', ['var' => 'description']]],
    ]);

    artisan('banking:backfill-descriptions', ['--dry-run' => true])
        ->expectsOutputToContain('1 transaction(s) and 1 automation rule(s) would be reformatted.')
        ->assertSuccessful();

    expect($transaction->refresh()->description)->toBe('/TXT/D|BAR CONO');
    expect($rule->refresh()->rules_json)->toBe(['in' => ['/TXT/D|BAR CONO', ['var' => 'description']]]);
});

test('only touches the given user when one is provided', function () {
    $user = User::factory()->create();
    $mine = taggedTransaction(['user_id' => $user->id]);
    $theirs = taggedTransaction();
    $myRule = AutomationRule::factory()->for($user)->create([
        'rules_json' => ['in' => ['/TXT/D|BAR CONO', ['var' => 'description']]],
    ]);
    $theirRule = AutomationRule::factory()->for(User::factory())->create([
        'rules_json' => ['in' => ['/TXT/D|BAR CONO', ['var' => 'description']]],
    ]);

    artisan('banking:backfill-descriptions', ['--user' => $user->email])->assertSuccessful();

    expect($mine->refresh()->description)->toBe('BAR CONO');
    expect($theirs->refresh()->description)->toBe('/TXT/D|BAR CONO');
    expect($myRule->refresh()->rules_json)->toBe(['in' => ['BAR CONO', ['var' => 'description']]]);
    expect($theirRule->refresh()->rules_json)->toBe(['in' => ['/TXT/D|BAR CONO', ['var' => 'description']]]);
});

test('fails when the given user does not exist', function () {
    artisan('banking:backfill-descriptions', ['--user' => 'nobody@example.com'])
        ->expectsOutputToContain("User with email 'nobody@example.com' not found.")
        ->assertFailed();
});
