<?php

use App\Models\Achievement;
use App\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;

function achiever(string ...$keys): User
{
    $user = User::factory()->create(['currency_code' => 'EUR', 'locale' => 'en']);

    foreach ($keys as $key) {
        Achievement::factory()->key($key)->create([
            'user_id' => $user->id,
            'space_id' => $user->activeSpace()->id,
            'achieved_on' => '2026-03-02',
        ]);
    }

    return $user;
}

/**
 * @return array<string, mixed>
 */
function medal(array $tracks, string $key): array
{
    return collect($tracks)->flatMap(fn (array $track): array => $track['medals'])->firstWhere('key', $key);
}

it('lists the whole catalog with the earned medals, the next rung named and the rest as silhouettes', function () {
    $response = withHeaders(apiV1Bearer(achiever('transactions.1', 'net_worth.1')))
        ->getJson('/api/v1/achievements')
        ->assertOk()
        ->assertJsonPath('data.currency', 'EUR')
        ->assertJsonPath('data.unlocked', 2)
        ->assertJsonPath('data.total', 59)
        ->assertJsonPath('data.streak', null)
        ->assertJsonPath('data.latest.achieved_on', '2026-03-02')
        ->assertJsonMissingPath('has_more');

    $tracks = $response->json('data.tracks');

    expect(medal($tracks, 'transactions.1'))->toMatchArray(['state' => 'earned', 'name' => 'First transaction', 'achieved_on' => '2026-03-02'])
        ->and(medal($tracks, 'transactions.2'))->toMatchArray(['state' => 'next', 'name' => 'Transactions recorded'])
        ->and(medal($tracks, 'transactions.2')['progress']['goal'])->toBe(50)
        ->and(medal($tracks, 'safety.2'))->toMatchArray(['state' => 'locked', 'name' => null, 'icon' => null, 'figure' => null, 'progress' => null]);
});

it('never exposes another user\'s medals', function () {
    achiever('transactions.1');

    $response = withHeaders(apiV1Bearer(User::factory()->create()))
        ->getJson('/api/v1/achievements')
        ->assertOk()
        ->assertJsonPath('data.unlocked', 0)
        ->assertJsonPath('data.latest', null);

    expect(collect($response->json('data.tracks'))->flatMap(fn (array $track): array => $track['medals'])->pluck('state')->unique()->all())
        ->not->toContain('earned');
});

it('needs a bearer token', function () {
    getJson('/api/v1/achievements')->assertUnauthorized();
});
