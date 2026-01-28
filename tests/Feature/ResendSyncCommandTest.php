<?php

use App\Models\User;
use App\Services\ResendService;

use function Pest\Laravel\artisan;
use function Pest\Laravel\mock;

test('resend:sync syncs all users to resend', function () {
    config(['services.resend.key' => 'test-api-key']);

    $users = User::factory()->count(3)->create();

    $resendService = mock(ResendService::class);
    $resendService->shouldReceive('createContact')->times(3);

    artisan('resend:sync')
        ->expectsOutputToContain('Syncing 3 users to Resend...')
        ->expectsOutputToContain('Synced 3 users to Resend.')
        ->assertSuccessful();
});

test('resend:sync fails when api key is not configured', function () {
    config(['services.resend.key' => null]);

    artisan('resend:sync')
        ->expectsOutputToContain('Resend API key not configured.')
        ->assertFailed();
});

test('resend:sync handles empty users', function () {
    config(['services.resend.key' => 'test-api-key']);

    artisan('resend:sync')
        ->expectsOutputToContain('No users to sync.')
        ->assertSuccessful();
});
