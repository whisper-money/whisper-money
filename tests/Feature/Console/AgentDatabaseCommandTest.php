<?php

use App\Models\User;

use function Pest\Laravel\artisan;

test('runs a query and returns json by default', function () {
    $user = User::factory()->create(['email' => 'agent-db@example.com']);

    artisan('agent:db', ['query' => "select email from users where id = '{$user->id}'"])
        ->expectsOutputToContain('agent-db@example.com')
        ->assertSuccessful();
});

test('renders the result as a table when requested', function () {
    User::factory()->create(['email' => 'table-format@example.com']);

    artisan('agent:db', [
        'query' => "select email from users where email = 'table-format@example.com'",
        '--format' => 'table',
    ])
        ->expectsOutputToContain('table-format@example.com')
        ->assertSuccessful();
});

test('rejects an unknown format', function () {
    artisan('agent:db', ['query' => 'select 1', '--format' => 'xml'])
        ->expectsOutputToContain('Invalid format')
        ->assertFailed();
});

test('reports query errors gracefully', function () {
    artisan('agent:db', ['query' => 'select * from non_existent_table'])
        ->assertFailed();
});
