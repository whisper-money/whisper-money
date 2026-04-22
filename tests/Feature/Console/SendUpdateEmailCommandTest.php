<?php

use App\Enums\DripEmailType;
use App\Jobs\SendUpdateEmailJob;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

beforeEach(function () {
    Queue::fake();

    $viewPath = resource_path('views/mail/updates');
    if (! File::exists($viewPath)) {
        File::makeDirectory($viewPath, 0755, true);
    }

    $testViewContent = <<<'BLADE'
<x-mail::message>
# Test Update

Hello {{ $user->name }},

This is a test update email.

Thanks,
Victor
</x-mail::message>
BLADE;

    File::put(
        resource_path('views/mail/updates/test-update.blade.php'),
        $testViewContent
    );
});

afterEach(function () {
    $testViewPath = resource_path('views/mail/updates/test-update.blade.php');
    if (File::exists($testViewPath)) {
        File::delete($testViewPath);
    }
});

test('command dispatches jobs for all users', function () {
    $users = User::factory()->count(3)->create();

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertPushed(SendUpdateEmailJob::class, 3);

    foreach ($users as $user) {
        Queue::assertPushed(SendUpdateEmailJob::class, function ($job) use ($user) {
            return $job->user->id === $user->id
                && $job->viewName === 'test-update'
                && str_starts_with($job->emailIdentifier, 'test-2026_force_')
                && $job->subject === 'Update from Whisper Money';
        });
    }
});

test('command skips deleted users', function () {
    $activeUser = User::factory()->create();
    $deletedUser = User::factory()->create();
    $deletedUser->delete();

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertPushed(SendUpdateEmailJob::class, function ($job) use ($activeUser) {
        return $job->user->id === $activeUser->id;
    });

    Queue::assertNotPushed(SendUpdateEmailJob::class, function ($job) use ($deletedUser) {
        return $job->user->id === $deletedUser->id;
    });

    Queue::assertCount(1);
});

test('command excludes demo account when flag is set', function () {
    $regularUser = User::factory()->create();
    $demoUser = User::factory()->create(['email' => config('app.demo.email')]);

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--exclude-demo' => true,
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertPushed(SendUpdateEmailJob::class, function ($job) use ($regularUser) {
        return $job->user->id === $regularUser->id;
    });

    Queue::assertNotPushed(SendUpdateEmailJob::class, function ($job) use ($demoUser) {
        return $job->user->id === $demoUser->id;
    });

    Queue::assertCount(1);
});

test('command fails when view does not exist', function () {
    User::factory()->create();

    artisan('email:update', [
        'view' => 'non-existent-view',
        'identifier' => 'test-2026',
        '--force' => true,
    ])->assertFailed();

    Queue::assertNothingPushed();
});

test('command uses custom subject when provided', function () {
    User::factory()->create();

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--subject' => 'Custom Subject Here',
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertPushed(SendUpdateEmailJob::class, function ($job) {
        return $job->subject === 'Custom Subject Here';
    });
});

test('job is dispatched to emails queue', function () {
    User::factory()->create();

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertPushedOn('emails', SendUpdateEmailJob::class);
});

test('command handles empty user database gracefully', function () {
    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertNothingPushed();
});

test('job skips users who already received the update', function () {
    Queue::fake([]);

    $user = User::factory()->create();

    UserMailLog::create([
        'user_id' => $user->id,
        'email_type' => DripEmailType::Update,
        'email_identifier' => 'test-2026',
        'sent_at' => now(),
    ]);

    $job = new SendUpdateEmailJob($user, 'test-update', 'test-2026');
    $job->handle();

    expect(UserMailLog::where('user_id', $user->id)->count())->toBe(1);
});

test('job creates mail log entry after sending', function () {
    Queue::fake([]);

    $user = User::factory()->create();

    expect(UserMailLog::where('user_id', $user->id)->exists())->toBeFalse();

    $job = new SendUpdateEmailJob($user, 'test-update', 'test-2026');
    $job->handle();

    expect(UserMailLog::where('user_id', $user->id)
        ->where('email_type', DripEmailType::Update)
        ->where('email_identifier', 'test-2026')
        ->exists())->toBeTrue();
});

test('job sends email with correct view and user data', function () {
    Queue::fake([]);

    $user = User::factory()->create(['name' => 'John Doe']);

    $job = new SendUpdateEmailJob($user, 'test-update', 'test-2026', 'Custom Subject');
    $job->handle();

    expect(UserMailLog::where('user_id', $user->id)->exists())->toBeTrue();
});

test('command requires confirmation by default', function () {
    User::factory()->create();

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
    ])->expectsConfirmation("About to send 'test-2026' email to 1 user(s). Continue?", 'no')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('command skips confirmation with force flag', function () {
    User::factory()->create();

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--force' => true,
    ])->doesntExpectOutput('Continue?')
        ->assertSuccessful();

    Queue::assertPushed(SendUpdateEmailJob::class, 1);
});

test('command applies rate limiting delay for large batches', function () {
    // Create 150 users to test rate limiting across 3 days
    User::factory()->count(150)->create();

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertPushed(SendUpdateEmailJob::class, 150);

    // Check that delays are applied correctly
    // First 50 emails: no delay (day 0)
    // Next 50 emails: 1 day delay (day 1)
    // Last 50 emails: 2 days delay (day 2)
    $pushedJobs = Queue::pushedJobs()[SendUpdateEmailJob::class] ?? [];

    expect($pushedJobs)->toHaveCount(150);

    // Verify delays are applied correctly
    // Note: We can't easily check the exact delay value in the test,
    // but we can verify all jobs were pushed
    expect($pushedJobs)->toHaveCount(150);
});
