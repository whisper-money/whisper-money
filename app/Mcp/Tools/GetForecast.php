<?php

namespace App\Mcp\Tools;

use App\Features\Forecast;
use App\Mcp\Tools\Concerns\PresentsForecast;
use App\Models\User;
use App\Services\Forecast\ForecastPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Pennant\Feature;

/**
 * The cash-flow forecast ("Previsión") the forecast page shows, for every
 * account it includes in a space together with their total (the page's
 * "All accounts" view), or for one of them. Served only to users with
 * the Forecast flag on: for anyone else the tool is neither listed nor
 * callable. Without Pro it returns the month in progress and the next one
 * only, the same months the page shows.
 */
#[IsReadOnly]
#[Description('Cash-flow forecast per account: each month\'s income, expenses, lowest balance and date, close and probable range, plus strong/soft low-balance alerts and their causes.')]
class GetForecast extends McpTool
{
    use PresentsForecast;

    private const HORIZONS = [3, 6, 12];

    private const DEFAULT_HORIZON = 12;

    public function shouldRegister(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Feature::for($user)->active(Forecast::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account' => $schema->string()->description('Optional account id (from list_accounts) to return only that account. Default: every account the forecast includes, each in its own currency, plus all_accounts, their total in the user\'s currency.'),
            'horizon' => $schema->integer()->enum(self::HORIZONS)->description('Months ahead after the month in progress: 3, 6 or 12 (default 12). Without Pro only the next month is returned, whatever the horizon.'),
            'space' => $schema->string()->description('Optional space id; defaults to the personal space.'),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $validated = $request->validate([
            'account' => ['nullable', 'string'],
            'horizon' => ['nullable', 'integer', Rule::in(self::HORIZONS)],
        ]);
        $space = $this->resolveSpace($request, $user);
        $accountId = $validated['account'] ?? null;
        $overview = app(ForecastPresenter::class)->overview($user, $space->id, $accountId, (int) ($validated['horizon'] ?? self::DEFAULT_HORIZON));

        if ($overview === null) {
            throw ValidationException::withMessages([
                'account' => "Account {$accountId} is not one of yours the forecast includes in space {$space->id}. Call list_accounts to see valid ids.",
            ]);
        }

        return $this->json([
            'today' => $overview['today'],
            'horizon' => $overview['horizon'],
            'plan' => $overview['isPro'] ? 'pro' : 'free',
            // Without Pro fewer months than the horizon come back.
            'months_shown' => count($overview['accounts'][0]['months'] ?? []),
            'all_accounts' => $overview['all'] === null ? null : $this->presentForecastTotal($overview['all']),
            'accounts' => array_map(fn (array $forecast): array => $this->presentForecastAccount($forecast), $overview['accounts']),
        ]);
    }
}
