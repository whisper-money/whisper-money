<?php

use App\Listeners\SyncUserToResendListener;
use App\Models\User;
use App\Services\ResendService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\mock;

test('user is synced to resend contacts', function () {
    config(['services.resend.key' => 'test-api-key']);

    $user = User::factory()->create([
        'name' => 'Steve Wozniak',
        'email' => 'steve@example.com',
    ]);

    $resendService = mock(ResendService::class);
    $resendService->shouldReceive('createContact')
        ->once()
        ->with(Mockery::on(fn ($u) => $u->id === $user->id));

    (new SyncUserToResendListener($resendService))->handle(new Verified($user));
});

test('listener skips sync when api key is not configured', function () {
    config(['services.resend.key' => null]);

    Log::shouldReceive('warning')
        ->once()
        ->with('Resend API key not configured, skipping contact sync');

    $resendService = mock(ResendService::class);
    $resendService->shouldNotReceive('createContact');

    $user = User::factory()->create();

    (new SyncUserToResendListener($resendService))->handle(new Verified($user));
});
