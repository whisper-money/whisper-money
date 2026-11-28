<?php

declare(strict_types=1);

use App\Enums\WidgetOrigin;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\RenderWidget;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Services\Widgets\WidgetDefinitionService;

/**
 * render_widget for real: the widget preview bundle drawn by headless
 * Chromium (scripts/render-widget.mjs), so what the agent is told about its
 * widget is what a browser actually laid out. Lives in the Browser suite
 * because that is the CI job with Playwright's Chromium and the built assets.
 * The tool's own rules (whose widget, error paths) are in
 * tests/Feature/Mcp/McpRenderWidgetTest.php with the browser faked.
 */
beforeEach(function (): void {
    config(['subscriptions.enabled' => false]);
    fakeCurrencyApi();
});

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function renderWidgetForReal(User $user, WidgetDefinition $widget, array $arguments = []): array
{
    $user->withAccessToken($user->createToken('mcp', ['mcp:read'])->accessToken);

    $response = WhisperMoneyServer::actingAs($user)->tool(RenderWidget::class, ['widget_id' => $widget->id, ...$arguments]);
    $result = (fn (): array => $this->response->toArray()['result'])->call($response);

    return json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
}

function renderedWidget(User $user, string $source, string $width = '1/2', string $height = 'm'): WidgetDefinition
{
    return app(WidgetDefinitionService::class)->create($user, [
        'name' => 'Supermercado por mes',
        'size' => ['width' => $width, 'height' => $height, 'allowed_widths' => ['1/3', '1/2', '1/1']],
        'data' => [
            'groceries' => ['endpoint' => 'GET /transactions', 'params' => ['from' => '2026-01-01'], 'paginate' => 'all'],
        ],
    ], $source, WidgetOrigin::Mcp);
}

function userWithGroceries(): User
{
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    foreach (['2026-08-03' => -4210, '2026-09-11' => -3890, '2026-10-02' => -5120] as $date => $amount) {
        Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'transaction_date' => $date, 'amount' => $amount]);
    }

    return $user;
}

/** A bar chart of the groceries by month; $dataKey is what its bars read. */
function groceriesChartSource(string $dataKey = 'total'): string
{
    return <<<TSX
        import { useWidgetData } from '@whisper-money/widget-sdk';
        import { Bar, BarChart, ResponsiveContainer, XAxis } from 'recharts';

        export default function Groceries() {
            const { groceries } = useWidgetData();
            const totals = new Map();

            for (const row of groceries.data) {
                const month = row.transaction_date.slice(0, 7);
                totals.set(month, (totals.get(month) ?? 0) + Math.abs(row.amount));
            }

            const months = [...totals.entries()].sort().map(([month, total]) => ({ month, total }));

            return (
                <div className="flex h-full flex-col gap-2 p-4">
                    <h3 className="text-sm font-medium text-muted-foreground">Supermercado por mes</h3>
                    <div className="min-h-0 flex-1">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={months}>
                                <XAxis dataKey="month" tickLine={false} axisLine={false} />
                                <Bar dataKey="{$dataKey}" fill="var(--color-chart-1)" />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </div>
            );
        }
        TSX;
}

it('draws a chart widget with the user\'s data at its dashboard cell size', function () {
    $user = userWithGroceries();
    $widget = renderedWidget($user, groceriesChartSource());

    $json = renderWidgetForReal($user, $widget);

    expect($json['rendered'])->toBeTrue()
        ->and($json['status'])->toBe('ready')
        ->and($json['size'])->toMatchArray(['width' => '1/2', 'height' => 'm', 'width_px' => 604, 'height_px' => 256])
        ->and($json['errors'])->toBe([])
        ->and($json['html'])->toContain('Supermercado por mes')
        ->and($json['html'])->toContain('recharts-bar-rectangle')
        // Its three months, as the axis ticks show them.
        ->and($json['html'])->toContain('2026-08')
        ->and($json['html'])->toContain('2026-10')
        // Long geometry is left out; the browser's business.
        ->and($json['html'])->not->toMatch('/ d="M[^"]{80,}"/')
        ->and(array_column($json['diagnostics'], 'kind'))->not->toContain('chart_empty', 'chart_no_size');
});

it('says when a chart draws no series', function () {
    $user = userWithGroceries();
    $widget = renderedWidget($user, groceriesChartSource(dataKey: 'amount'));

    $json = renderWidgetForReal($user, $widget, ['theme' => 'dark']);

    expect($json['rendered'])->toBeTrue()
        ->and(array_column($json['diagnostics'], 'kind'))->toContain('chart_empty');
});

it('reports text cut short and content the cell clips', function () {
    $user = userWithGroceries();
    $widget = renderedWidget($user, <<<'TSX'
        export default function Crowded() {
            return (
                <div className="flex flex-col gap-2 p-4">
                    <p className="truncate text-sm">Una descripción demasiado larga para una celda de un tercio del dashboard</p>
                    {Array.from({ length: 12 }, (_, index) => (
                        <p key={index} className="text-2xl">Fila {index}</p>
                    ))}
                </div>
            );
        }
        TSX, width: '1/3');

    $json = renderWidgetForReal($user, $widget);
    $kinds = array_column($json['diagnostics'], 'kind');

    expect($json['size']['width_px'])->toBe(395)
        ->and($kinds)->toContain('text_truncated', 'cell_overflow', 'clipped_by_cell');
});

it('answers with the error a widget throws while rendering', function () {
    $user = userWithGroceries();
    $widget = renderedWidget($user, <<<'TSX'
        import { useWidgetData } from '@whisper-money/widget-sdk';

        export default function Crashes() {
            const { missing } = useWidgetData();

            return <p>{missing.length}</p>;
        }
        TSX);

    $json = renderWidgetForReal($user, $widget);

    expect($json['rendered'])->toBeFalse()
        ->and($json['status'])->toBe('crashed')
        ->and($json['errors'][0]['phase'])->toBe('render')
        ->and($json['errors'][0]['message'])->toContain('length');
});
