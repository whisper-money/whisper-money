<?php

namespace App\Http\Controllers;

use App\Http\Requests\RunAdminCommandRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class AdminController extends Controller
{
    /**
     * The only commands /admin will run, grouped the way the page lists them.
     * The key is the command line itself, so one that would otherwise post to
     * Discord is pinned here to its console-only form. The value says whether
     * the free-text arguments field may add anything to it: `false` is for the
     * commands whose options can do damage, and for the ones that take none.
     *
     * Keep this to commands that finish in seconds: the request waits for them.
     *
     * @var array<string, array<string, bool>>
     */
    private const COMMANDS = [
        'Diagnostics' => [
            'banking:health' => true,
            'banks:check-logos' => false,
            'stats:daily-report --no-discord' => false,
            'stats:ai-cohort-report --no-discord' => true,
            'stats:mcp-usage' => true,
            'stats:stuck-cohort-report --no-discord' => false,
            'stats:subscription-funnel --no-discord' => true,
        ],
        'Maintenance' => [
            'budgets:generate-periods' => false,
            'resend:sync' => false,
            // No arguments: `--email=` points the reseed at any account it
            // names and force-deletes that account's real data first.
            'demo:reset' => false,
            'email:monthly-summary-preview' => true,
        ],
        'Feature flags' => [
            'feature:enable' => true,
            'feature:disable' => true,
        ],
        'Bank notices' => [
            'banking:notify-connect-failure' => true,
            'banking:notify-outage' => true,
            'banking:notify-users' => true,
        ],
        'Users' => [
            'user:delete' => true,
            'ai:delete-consent' => true,
        ],
    ];

    /**
     * Seconds a run may take, raised for the request and put back afterwards.
     * A backstop, not a graceful one: PHP's timeout is fatal and uncatchable,
     * so an admin who trips it gets a dead request rather than the partial
     * output below. Keeping the curated list to commands that finish in
     * seconds is the real protection.
     */
    private const RUN_TIMEOUT_SECONDS = 60;

    private const USERS_PER_PAGE = 25;

    /**
     * Every allowed command line, mapped to whether it takes arguments.
     *
     * @return array<string, bool>
     */
    public static function allowedCommands(): array
    {
        return array_merge(...array_values(self::COMMANDS));
    }

    public function index(): Response
    {
        return Inertia::render('admin/index', [
            'commands' => self::COMMANDS,
            'users' => User::query()
                ->latest()
                ->paginate(self::USERS_PER_PAGE)
                ->through(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'locale' => $user->locale,
                    'currency_code' => $user->currency_code,
                    'created_at' => $user->created_at?->toIso8601String(),
                    'last_active_at' => $user->last_active_at?->toIso8601String(),
                ]),
            'result' => session('commandResult'),
        ]);
    }

    public function run(RunAdminCommandRequest $request): RedirectResponse
    {
        // `--no-interaction` on every run: nothing here can answer a prompt, so a
        // command that confirms takes its default instead of waiting on STDIN.
        // It is part of the line that runs, so it is part of the line we echo.
        $command = trim(implode(' ', array_filter([
            $request->validated('command'),
            $request->validated('arguments') ?? '',
            '--no-interaction',
        ])));
        $output = new BufferedOutput;
        $startedAt = microtime(true);

        // set_time_limit() is process-wide, not request-wide, so the budget has
        // to go back where it was: a worker that keeps the process alive (the
        // test runner, Octane) would otherwise inherit this one and die later,
        // somewhere unrelated.
        $previousLimit = (int) ini_get('max_execution_time');
        set_time_limit(self::RUN_TIMEOUT_SECONDS);

        try {
            $exitCode = Artisan::call($command, [], $output);
        } catch (Throwable $e) {
            // A throwing command still printed something up to that point, and
            // the reason it threw is the most useful line of all. Report it as a
            // failed run instead of losing both to a 500 page.
            $exitCode = 1;
            $output->writeln($e->getMessage());
        } finally {
            set_time_limit($previousLimit);
        }

        return back()->with('commandResult', [
            'command' => $command,
            'exit_code' => $exitCode,
            'output' => $output->fetch(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'ran_at' => now()->toIso8601String(),
        ]);
    }
}
