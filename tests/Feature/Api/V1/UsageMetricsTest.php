<?php

use App\Enums\ApiCredential;
use App\Models\ApiRequest;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;

it('records one row per request with the endpoint, credential and status, and nothing else', function () {
    $user = User::factory()->create();

    withHeaders(apiV1Bearer($user))->getJson('/api/v1/spaces?space=secret')->assertOk();

    $row = ApiRequest::query()->sole();

    expect($row->only(['user_id', 'method', 'endpoint', 'credential', 'oauth_client_id', 'status']))->toBe([
        'user_id' => $user->id,
        'method' => 'GET',
        'endpoint' => 'api.v1.spaces.index',
        'credential' => ApiCredential::PersonalAccessToken,
        'oauth_client_id' => null,
        'status' => 200,
    ]);
});

it('records the OAuth client a request came through', function () {
    $user = User::factory()->create();
    $client = Passport::client()->forceFill([
        'name' => 'Budget app',
        'secret' => null,
        'redirect_uris' => ['https://budget.example/callback'],
        'grant_types' => ['authorization_code'],
        'revoked' => false,
    ]);
    $client->save();
    Passport::actingAs($user, ['api:use'], client: $client);

    getJson('/api/v1/spaces')->assertOk();

    expect(ApiRequest::query()->sole())
        ->credential->toBe(ApiCredential::OAuth)
        ->oauth_client_id->toBe($client->getKey());
});

it('records requests the gate refuses, with their status', function () {
    config(['subscriptions.enabled' => true]);

    withHeaders(apiV1Bearer(User::factory()->create()))->getJson('/api/v1/spaces')->assertForbidden();

    expect(ApiRequest::query()->sole()->status)->toBe(403);
});

it('does not record unauthenticated requests', function () {
    getJson('/api/v1/spaces')->assertUnauthorized();

    expect(ApiRequest::query()->count())->toBe(0);
});

it('never breaks a request when the metric cannot be written', function () {
    ApiRequest::creating(fn () => throw new RuntimeException('metrics table is down'));

    withHeaders(apiV1Bearer(User::factory()->create()))->getJson('/api/v1/spaces')->assertOk();
});

it('reports usage per endpoint, status, credential, user and day', function () {
    $heavy = User::factory()->create(['email' => 'heavy@example.com']);
    $light = User::factory()->create(['email' => 'light@example.com']);

    ApiRequest::factory()->count(3)->create(['user_id' => $heavy->id]);
    ApiRequest::factory()->oauth('client-123')->create(['user_id' => $light->id, 'endpoint' => 'api.v1.transactions.index', 'status' => 429]);
    ApiRequest::factory()->create(['user_id' => $light->id, 'created_at' => now()->subDays(90)]);

    $this->artisan('stats:api-usage', ['--days' => 30])
        ->expectsOutputToContain('Requests: 4')
        ->expectsOutputToContain('api.v1.spaces.index')
        ->expectsOutputToContain('api.v1.transactions.index')
        ->expectsOutputToContain('429')
        ->expectsOutputToContain('oauth:client-123')
        ->expectsOutputToContain('heavy@example.com')
        ->expectsOutputToContain('light@example.com')
        ->assertSuccessful();
});

it('leaves the shared accounts out of the report', function () {
    config(['app.press.email' => 'press@example.test']);
    ApiRequest::factory()->create(['user_id' => User::factory()->create(['email' => 'press@example.test'])->id]);

    $this->artisan('stats:api-usage')
        ->expectsOutputToContain('No API requests')
        ->assertSuccessful();
});

it('prunes rows older than the retention window', function () {
    ApiRequest::factory()->create(['created_at' => now()->subDays(91)]);
    $recent = ApiRequest::factory()->create(['created_at' => now()->subDays(89)]);

    test()->artisan('model:prune', ['--model' => [ApiRequest::class]])->assertSuccessful();

    expect(ApiRequest::query()->pluck('id')->all())->toBe([$recent->id]);
});

it('schedules the pruning daily', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'model:prune')
            && str_contains((string) $event->command, 'ApiRequest'));

    expect($event)->not->toBeNull();
});
