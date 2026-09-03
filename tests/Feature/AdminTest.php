<?php

use App\Http\Controllers\AdminController;
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

test('the arguments the curated commands actually need are accepted', function (string $command, string $arguments) {
    Artisan::partialMock()
        ->shouldReceive('call')
        ->once()
        ->with("{$command} {$arguments} --no-interaction", [], Mockery::type(BufferedOutput::class))
        ->andReturn(0);

    $this->actingAs(admin())
        ->post('/admin/run', ['command' => $command, 'arguments' => $arguments])
        ->assertValid();
})->with([
    // A bank name is two words and needs its quotes.
    ['banking:notify-outage', '"Banco Mediolanum" --country=ES --force'],
    ['banking:notify-users', '"Trade Republic" resources/notices/my-notice --dry-run'],
    // A feature is a class name and needs its backslashes; a rollout is a percentage.
    ['feature:enable', 'App\\Features\\SplitTransactions 25%'],
    ['user:delete', 'someone@whisper.money'],
    ['stats:mcp-usage', '--days=7 --top=5'],
]);

test('a command that takes no arguments refuses them, so demo:reset cannot be aimed at a real account', function () {
    Artisan::partialMock()->shouldNotReceive('call');

    $this->actingAs(admin())
        ->post('/admin/run', [
            'command' => 'demo:reset',
            'arguments' => '--email=victim@whisper.test',
        ])
        ->assertInvalid('arguments');
});

test('a command that takes no arguments still runs when none are given', function () {
    Artisan::partialMock()
        ->shouldReceive('call')
        ->once()
        ->with('demo:reset --no-interaction', [], Mockery::type(BufferedOutput::class))
        ->andReturn(0);

    $this->actingAs(admin())
        ->post('/admin/run', ['command' => 'demo:reset', 'arguments' => ''])
        ->assertValid()
        ->assertRedirect();
});

test('an allowed command runs and its output and exit code come back', function () {
    Artisan::partialMock()
        ->shouldReceive('call')
        ->once()
        ->with('banking:health --history-days=3 --no-interaction', [], Mockery::type(BufferedOutput::class))
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
        ->assertSessionHas('commandResult', fn (array $result) => $result['command'] === 'banking:health --history-days=3 --no-interaction'
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

test('a run leaves the process execution limit where it found it', function () {
    // set_time_limit() is process-wide. Left raised, it follows the worker into
    // whatever test runs next and kills it there, which is how CI found this.
    $before = ini_get('max_execution_time');

    Artisan::partialMock()->shouldReceive('call')->once()->andReturn(0);

    $this->actingAs(admin())->post('/admin/run', ['command' => 'resend:sync']);

    expect(ini_get('max_execution_time'))->toBe($before);
});

test('every curated command exists in the artisan registry', function () {
    $registered = array_keys(Artisan::all());

    foreach (array_keys(AdminController::allowedCommands()) as $line) {
        expect($registered)->toContain(explode(' ', $line)[0]);
    }
});

test('a non-admin cannot run a command either', function () {
    Artisan::partialMock()->shouldNotReceive('call');

    $this->actingAs(User::factory()->create())
        ->post('/admin/run', ['command' => 'resend:sync'])
        ->assertNotFound();
});
