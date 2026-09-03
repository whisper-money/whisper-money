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
     * Each entry is the command line itself, so one that would otherwise post to
     * Discord is pinned here to its console-only form.
     *
     * Keep this to commands that finish in seconds: the request waits for them.
     *
     * @var array<string, array<int, string>>
     */
    private const COMMANDS = [
        'Diagnostics' => [
            'banking:health',
            'banks:check-logos',
            'stats:daily-report --no-discord',
            'stats:mcp-usage',
        ],
        'Maintenance' => [
            'budgets:generate-periods',
            'resend:sync',
            'demo:reset',
            'email:monthly-summary-preview',
        ],
    ];

    /**
     * Seconds a run may take before PHP stops it, so a command that turns out to
     * be slower than expected cannot hold the request open indefinitely.
     */
    private const RUN_TIMEOUT_SECONDS = 60;

    private const USERS_PER_PAGE = 25;

    /**
     * Every allowed command line, flattened for validation and for the select.
     *
     * @return array<int, string>
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
        set_time_limit(self::RUN_TIMEOUT_SECONDS);

        $command = trim($request->validated('command').' '.($request->validated('arguments') ?? ''));
        $output = new BufferedOutput;
        $startedAt = microtime(true);

        try {
            $exitCode = Artisan::call($command, [], $output);
        } catch (Throwable $e) {
            // A throwing command still printed something up to that point, and
            // the reason it threw is the most useful line of all. Report it as a
            // failed run instead of losing both to a 500 page.
            $exitCode = 1;
            $output->writeln($e->getMessage());
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
