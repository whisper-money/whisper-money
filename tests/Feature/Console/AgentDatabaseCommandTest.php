<?php

use App\Models\User;

use function Pest\Laravel\artisan;

test('runs a query and prints the result', function (array $format) {
    User::factory()->create(['email' => 'agent-db@example.com']);

    artisan('agent:db', ['query' => "select email from users where email = 'agent-db@example.com'", ...$format])
        ->expectsOutputToContain('agent-db@example.com')
        ->assertSuccessful();
})->with([
    'as json by default' => [[]],
    'as a table when requested' => [['--format' => 'table']],
]);

test('rejects an unknown format', function () {
    artisan('agent:db', ['query' => 'select 1', '--format' => 'xml'])
        ->expectsOutputToContain('Invalid format')
        ->assertFailed();
});

test('reports query errors gracefully', function () {
    artisan('agent:db', ['query' => 'select * from non_existent_table'])
        ->assertFailed();
});
