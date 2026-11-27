<?php

use App\Http\Controllers\AdminController;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

test('the page lists the ten newest users and how many there are', function () {
    $admin = admin();
    $newest = User::factory()->create(['created_at' => now()->addMinute()]);
    User::factory()->count(12)->create(['created_at' => now()->subDay()]);

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('latestUsers', 10)
            ->where('latestUsers.0.id', $newest->id)
            ->where('userCount', 14)
            ->missing('users')
        );
});

test('the page opens the chart on the month in progress', function () {
    $this->travelTo('2026-11-27 12:00:00');
    $admin = admin();
    User::factory()->count(2)->create(['created_at' => '2026-11-20 09:00:00']);

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('signupChart.period_type', 'month')
            ->where('signupChart.period', '2026-11')
            ->has('signupChart.points', 30)
            ->where('signupChart.points.19.start', '2026-11-20')
            ->where('signupChart.points.19.signups', 2)
            // The admin's own request is today's activity.
            ->where('signupChart.points.26.signups', 1)
            ->where('signupChart.points.26.active_users', 1)
            ->where('signupChart.points.27.signups', null)
        );
});

test('the chart follows the period in the query, and drops one that does not parse', function (array $query, string $type, string $period) {
    $this->travelTo('2026-11-27 12:00:00');

    $this->actingAs(admin())
        ->get('/admin?'.http_build_query($query))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('signupChart.period_type', $type)
            ->where('signupChart.period', $period)
        );
})->with([
    'a past month' => [['period_type' => 'month', 'period' => '2026-03'], 'month', '2026-03'],
    'a quarter' => [['period_type' => 'quarter', 'period' => '2026-Q2'], 'quarter', '2026-Q2'],
    'a year' => [['period_type' => 'year', 'period' => '2025'], 'year', '2025'],
    'a month that does not exist' => [['period_type' => 'month', 'period' => '2026-13'], 'month', '2026-11'],
    'a period of the wrong type' => [['period_type' => 'quarter', 'period' => '2026-03'], 'quarter', '2026-Q4'],
    'an unknown period type' => [['period_type' => 'week', 'period' => '2026-03'], 'month', '2026-03'],
]);

test('a partial reload of the chart computes nothing else', function () {
    $admin = admin();
    DB::enableQueryLog();

    $this->actingAs($admin)
        ->get('/admin?period_type=year&period=2025', [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'admin/index',
            'X-Inertia-Partial-Data' => 'signupChart',
        ])
        ->assertOk()
        ->assertJsonPath('props.signupChart.period', '2025')
        ->assertJsonMissingPath('props.latestUsers')
        ->assertJsonMissingPath('props.integrationRequests');

    $queries = collect(DB::getQueryLog())->pluck('query');

    expect($queries->filter(fn (string $query): bool => str_contains($query, 'integration_requests')))->toBeEmpty();
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
    ['feature:enable', 'App\\Features\\SampleRolloutFeature 25%'],
    ['user:delete', 'someone@whisper.money'],
    // The admin page always runs with --no-interaction, so deleting a user needs --force.
    ['user:delete', 'someone@whisper.money --force'],
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
