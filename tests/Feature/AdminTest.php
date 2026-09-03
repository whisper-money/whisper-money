<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

const ADMIN_EMAIL = 'admin@whisper.test';

beforeEach(function () {
    config(['app.admin_email' => ADMIN_EMAIL]);
});

function admin(): User
{
    return User::factory()->create(['email' => ADMIN_EMAIL]);
}

test('guests are sent to the login page', function () {
    $this->get('/admin')->assertRedirect();
});

test('a signed-in user who is not the admin gets a 404, not a 403', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertNotFound();
});

test('the admin account gets the page', function () {
    $this->actingAs(admin())
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/index'));
});

test('nobody is an admin while ADMIN_EMAIL is unset', function (?string $adminEmail) {
    config(['app.admin_email' => $adminEmail]);

    // Including a user whose own email is empty: it must not match an empty
    // config and let the gate open for everyone.
    $user = User::factory()->create();
    $user->forceFill(['email' => ''])->save();

    expect($user->isAdmin())->toBeFalse();

    $this->actingAs($user)->get('/admin')->assertNotFound();
})->with([null, '']);

test('the user list renders and paginates, newest first', function () {
    $admin = admin();
    User::factory()->count(30)->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('users.data', 25)
            ->where('users.total', 31)
            ->where('users.current_page', 1)
        );

    $this->actingAs($admin)
        ->get('/admin?page=2')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('users.data', 6)
            ->where('users.current_page', 2)
        );
});

test('a command that is not on the curated list is rejected', function () {
    Artisan::partialMock()->shouldNotReceive('call');

    $this->actingAs(admin())
        ->post('/admin/run', ['command' => 'migrate:fresh'])
        ->assertInvalid('command');
});

test('arguments cannot smuggle in a second command', function () {
    Artisan::partialMock()->shouldNotReceive('call');

    $this->actingAs(admin())
        ->post('/admin/run', [
            'command' => 'banking:health',
            'arguments' => '; rm -rf $(pwd)',
        ])
        ->assertInvalid('arguments');
});

test('an allowed command runs and its output and exit code come back', function () {
    Artisan::partialMock()
        ->shouldReceive('call')
        ->once()
        ->with('banking:health --history-days=3', [], Mockery::type(BufferedOutput::class))
        ->andReturnUsing(function (string $command, array $parameters, BufferedOutput $output): int {
            $output->writeln('Every bank is fine.');

            return 0;
        });

    $this->actingAs(admin())
        ->post('/admin/run', [
            'command' => 'banking:health',
            'arguments' => '--history-days=3',
        ])
        ->assertRedirect()
        ->assertSessionHas('commandResult', fn (array $result) => $result['command'] === 'banking:health --history-days=3'
            && $result['exit_code'] === 0
            && str_contains($result['output'], 'Every bank is fine.'));
});

test('a command that throws is reported as a failed run, keeping what it printed', function () {
    Artisan::partialMock()
        ->shouldReceive('call')
        ->once()
        ->andReturnUsing(function (string $command, array $parameters, BufferedOutput $output): int {
            $output->writeln('Halfway through…');

            throw new RuntimeException('The provider timed out');
        });

    $this->actingAs(admin())
        ->post('/admin/run', ['command' => 'resend:sync'])
        ->assertRedirect()
        ->assertSessionHas('commandResult', fn (array $result) => $result['exit_code'] === 1
            && str_contains($result['output'], 'Halfway through…')
            && str_contains($result['output'], 'The provider timed out'));
});

test('the result of a run is handed back to the page', function () {
    $this->actingAs(admin())
        ->withSession(['commandResult' => ['command' => 'resend:sync', 'exit_code' => 0, 'output' => 'Done.', 'duration_ms' => 12, 'ran_at' => now()->toIso8601String()]])
        ->get('/admin')
        ->assertInertia(fn ($page) => $page->where('result.output', 'Done.'));
});

test('a non-admin cannot run a command either', function () {
    Artisan::partialMock()->shouldNotReceive('call');

    $this->actingAs(User::factory()->create())
        ->post('/admin/run', ['command' => 'resend:sync'])
        ->assertNotFound();
});
