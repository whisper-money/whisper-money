<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.discord.webhook_url', 'https://discord.test/webhook');
});

test('posts yesterday user counts and stripe stats to discord', function () {
    Http::fake();
    bindMockStripeClientForStats([
        'active' => [makeStripeSubscription('eur', 1000, 'month')],
        'trialing' => [makeStripeSubscription('eur', 1000, 'month')],
    ]);

    $tz = 'Europe/Madrid';
    User::factory()->create(['created_at' => Carbon::now($tz)->subDay()->setTime(12, 0)->utc()]);
    User::factory()->create(['created_at' => Carbon::now($tz)->subDays(10)->utc()]);

    $this->artisan('stats:daily-report')->assertSuccessful();

    Http::assertSent(function ($request) {
        $embed = $request['embeds'][0];
        $users = collect($embed['fields'])->firstWhere('name', '👥 Users');

        return $request->url() === 'https://discord.test/webhook'
            && str_contains($users['value'], 'New yesterday: **1**')
            && str_contains($users['value'], 'Total: **2**')
            && collect($embed['fields'])->contains(fn ($f) => str_contains($f['value'], '€10.00'));
    });
});

test('prints to the console without posting when --no-discord is set', function () {
    Http::fake();
    bindMockStripeClientForStats([
        'active' => [makeStripeSubscription('eur', 1000, 'month')],
        'trialing' => [],
    ]);

    User::factory()->create(['created_at' => Carbon::now('Europe/Madrid')->subDay()->setTime(12, 0)->utc()]);

    $this->artisan('stats:daily-report', ['--no-discord' => true])->assertSuccessful();

    Http::assertNothingSent();
});

test('total reflects users at end of yesterday and excludes users created today', function () {
    Http::fake();
    bindMockStripeClientForStats(['active' => [], 'trialing' => []]);

    $tz = 'Europe/Madrid';
    User::factory()->create(['created_at' => Carbon::now($tz)->subDays(10)->utc()]);
    User::factory()->create(['created_at' => Carbon::now($tz)->subDay()->setTime(12, 0)->utc()]);
    User::factory()->create(['created_at' => Carbon::now($tz)->setTime(8, 0)->utc()]);

    $this->artisan('stats:daily-report')->assertSuccessful();

    Http::assertSent(function ($request) {
        $users = collect($request['embeds'][0]['fields'])->firstWhere('name', '👥 Users');

        return str_contains($users['value'], 'New yesterday: **1**')
            && str_contains($users['value'], 'Total: **2**');
    });
});

test('reports zero new users when none were created yesterday', function () {
    Http::fake();
    bindMockStripeClientForStats(['active' => [], 'trialing' => []]);

    User::factory()->create(['created_at' => Carbon::now('Europe/Madrid')->subDays(5)->utc()]);

    $this->artisan('stats:daily-report')->assertSuccessful();

    Http::assertSent(function ($request) {
        $users = collect($request['embeds'][0]['fields'])->firstWhere('name', '👥 Users');

        return str_contains($users['value'], 'New yesterday: **0**')
            && str_contains($users['value'], 'Total: **1**');
    });
});
