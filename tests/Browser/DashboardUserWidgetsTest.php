<?php

declare(strict_types=1);

use App\Enums\SystemWidget;
use App\Enums\WidgetOrigin;
use App\Features\CustomDashboards;
use App\Models\Account;
use App\Models\Category;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Services\Widgets\WidgetDefinitionService;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * User widgets on the real dashboard, saved through WidgetDefinitionService
 * from the fixtures in tests/Fixtures/widgets/valid, as the agent's tools
 * will save them: their data, their errors, their versions and safe mode.
 */
beforeEach(function (): void {
    config(['subscriptions.enabled' => false]);
    fakeCurrencyApi();
});

/**
 * @return array<string, mixed>
 */
function groceriesManifest(): array
{
    return [
        'name' => 'Groceries by month',
        'size' => ['width' => '1/2', 'height' => 'm', 'allowed_widths' => ['1/2', '1/1']],
        'data' => [
            'groceries' => ['endpoint' => 'GET /transactions', 'params' => ['from' => '2026-01-01'], 'paginate' => 'all'],
        ],
    ];
}

/**
 * The groceries widget, written against what `GET /transactions` really
 * answers. The fixtures in tests/Fixtures/widgets/valid exercise the
 * compiler and the runtime with made-up data of their own shape.
 */
function groceriesWidgetSource(): string
{
    return <<<'TSX'
        import { formatMoney, useWidgetData, useWidgetEnv } from '@whisper-money/widget-sdk';
        import { ShoppingCart } from 'lucide-react';

        export default function Groceries() {
            const { groceries } = useWidgetData();
            const { currency } = useWidgetEnv();
            const total = groceries.data.reduce((sum, row) => sum + Math.abs(row.amount), 0);
            const last = groceries.data.map((row) => row.transaction_date).sort().at(-1);

            return (
                <div className="flex h-full flex-col gap-2 p-4">
                    <h3 className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                        <ShoppingCart size={14} />
                        Supermercado este año
                    </h3>
                    <p className="text-2xl font-semibold tabular-nums">{formatMoney(total, currency)}</p>
                    <p className="text-xs text-muted-foreground">{groceries.data.length} compras · última el {last}</p>
                </div>
            );
        }
        TSX;
}

/** A component that reads data its manifest never asked for, and throws. */
function crashingWidgetSource(): string
{
    return <<<'TSX'
        import { useWidgetData } from '@whisper-money/widget-sdk';

        export default function Crashes() {
            const { missing } = useWidgetData();

            return <p>{missing.length}</p>;
        }
        TSX;
}

/**
 * A user with the flag on, a dashboard of their own as the default one with
 * the net worth chart, and a groceries widget saved by their agent on it.
 *
 * @return array{0: User, 1: Dashboard, 2: WidgetDefinition}
 */
function dashboardWithUserWidget(bool $flag = true): array
{
    $user = User::factory()->onboarded()->create();

    if ($flag) {
        Feature::for($user)->activate(CustomDashboards::class);
    }

    $account = Account::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'transaction_date' => '2026-09-03', 'amount' => -4210]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'transaction_date' => '2026-10-11', 'amount' => -3890]);

    $dashboard = Dashboard::factory()->for($user)->create(['name' => 'Mine']);
    $user->forceFill(['default_dashboard_id' => $dashboard->id])->save();
    DashboardWidget::factory()->for($dashboard)->system(SystemWidget::NetWorth)->create(['position' => 0, 'width' => '1/2', 'height' => 'm']);

    $service = app(WidgetDefinitionService::class);
    $widget = $service->create($user, groceriesManifest(), groceriesWidgetSource(), WidgetOrigin::Mcp);
    $service->place($dashboard, $widget);

    return [$user, $dashboard, $widget];
}

function userWidgetCell(WidgetDefinition $widget): string
{
    return "[data-user-widget=\"{$widget->id}\"]";
}

it('draws a saved user widget next to the system ones, with its data', function (bool $dark) {
    [$user, , $widget] = dashboardWithUserWidget();
    actingAs($user);

    $page = visit('/dashboard');

    if ($dark) {
        $page = $page->inDarkMode();
    }

    $page->assertSee('Supermercado este año')
        ->assertSee('2 compras · última el 2026-10-11')
        ->assertPresent(userWidgetCell($widget).' .wm-widget[data-status="ready"]')
        ->assertPresent('[data-widget="net-worth"]')
        ->assertMissing(userWidgetCell($widget).' [role="alert"]')
        ->assertNoJavascriptErrors();
})->with(['light' => false, 'dark' => true]);

it('keeps a widget that throws to its cell, stores its error, and goes back to a version that works', function () {
    [$user, , $widget] = dashboardWithUserWidget();
    app(WidgetDefinitionService::class)->update($widget, groceriesManifest(), crashingWidgetSource(), WidgetOrigin::Mcp);
    actingAs($user);

    $page = visit('/dashboard')
        ->assertSee('This widget stopped working')
        ->assertPresent('[data-widget="net-worth"]')
        ->assertDontSee('Supermercado este año');

    $page->wait(0.5);

    expect($widget->fresh()->last_error)
        ->revision->toBe(2)
        ->phase->toBe('render');

    $page->click('[aria-label="Options for Groceries by month"]')
        ->click('See the error and versions')
        ->assertSee('Last error')
        ->assertSee('Ask your agent to fix it')
        ->assertSee('Version 2');

    $page->click('[data-testid="user-widget-revision"]:has-text("Version 1") button')
        ->assertSee('Go back to version 1?')
        ->click('[role="alertdialog"] button:has-text("Go back")')
        ->assertSee('The widget is back to version 1.')
        ->assertSee('Supermercado este año')
        ->assertDontSee('This widget stopped working');

    expect($widget->fresh())->revision->toBe(3)
        ->and($widget->revisions()->where('number', 3)->value('restored_from'))->toBe(1);
});

it('adds a user widget in edit mode and sizes it among the ones its manifest admits', function () {
    [$user, $dashboard] = dashboardWithUserWidget();
    $budget = app(WidgetDefinitionService::class)->create(
        $user,
        [...groceriesManifest(), 'name' => 'Second groceries', 'size' => ['width' => '1/3', 'height' => 'm', 'allowed_widths' => ['1/3', '1/1']]],
        groceriesWidgetSource(),
        WidgetOrigin::Mcp,
    );
    actingAs($user);

    $page = visit('/dashboard')
        ->assertSee('Supermercado este año')
        ->press('Edit')
        ->press('Add widget')
        ->click('[role="menuitem"]:has-text("Second groceries")')
        ->assertPresent(userWidgetCell($budget).' .wm-widget[data-status="ready"]');

    $placement = $dashboard->widgets()->where('widget_definition_id', $budget->id)->firstOrFail();
    expect($placement->width->value)->toBe('1/3');

    $page->click('[aria-label="Resize Second groceries"]')
        ->assertSee('Full width')
        ->assertMissing('[role="menuitemradio"]:has-text("Half")')
        ->click('[role="menuitemradio"]:has-text("Full width")')
        ->wait(0.5);

    expect($placement->fresh()->width->value)->toBe('1/1');
    $page->assertNoJavascriptErrors();
});

it('opens in safe mode without user widgets and removes the one at fault', function () {
    [$user, $dashboard, $widget] = dashboardWithUserWidget();
    actingAs($user);

    $page = visit('/dashboard?safe=1')
        ->assertSee('Safe mode: your widgets are not loaded')
        ->assertPresent('[data-widget="net-worth"]')
        ->assertMissing('.wm-widget')
        ->assertSee('Not loaded in safe mode.');

    $page->click(userWidgetCell($widget).' button:has-text("Remove from dashboard")')
        ->assertMissing(userWidgetCell($widget));

    expect($dashboard->widgets()->whereNotNull('widget_definition_id')->exists())->toBeFalse();

    // With no user widget left there is nothing to keep off.
    $page->assertDontSee('Safe mode: your widgets are not loaded')
        ->assertPresent('[data-widget="net-worth"]')
        ->assertNoJavascriptErrors();
});

it('leaves safe mode and loads the user widgets', function () {
    [$user] = dashboardWithUserWidget();
    actingAs($user);

    visit('/dashboard?safe=1')
        ->assertSee('Safe mode: your widgets are not loaded')
        ->click('Load widgets')
        ->assertSee('Supermercado este año')
        ->assertDontSee('Safe mode: your widgets are not loaded')
        ->assertNoJavascriptErrors();
});

it('draws no user widget with the flag off', function () {
    [$user] = dashboardWithUserWidget(flag: false);
    actingAs($user);

    visit('/dashboard')
        ->assertSee('Overview of your financial health')
        ->assertMissing('[data-user-widget]')
        ->assertMissing('.wm-widget')
        ->assertDontSee('Supermercado este año')
        ->assertNoJavascriptErrors();
});

it('fits a phone: user and system widgets full width', function () {
    [$user, , $widget] = dashboardWithUserWidget();
    actingAs($user);

    $page = visit('/dashboard')
        ->on()
        ->iPhoneSE()
        ->navigate('/dashboard')
        ->assertSee('Supermercado este año');

    $layout = $page->script(<<<'JS'
        (() => {
            const grid = document.querySelector('[data-testid="dashboard-grid"]');
            const main = document.querySelector('[data-slot="sidebar-inset"]');

            return {
                grid: grid.getBoundingClientRect().width,
                cells: [...grid.children].map((cell) => cell.getBoundingClientRect().width),
                overflow: main.scrollWidth - main.clientWidth,
            };
        })()
    JS);

    expect($layout['overflow'])->toBeLessThanOrEqual(0)
        ->and($layout['cells'])->toHaveCount(2)
        ->and(array_unique($layout['cells']))->toBe([$layout['grid']]);

    // The menu is only offered while editing, and then without hovering: a
    // phone has no pointer to hover with.
    $page->assertMissing('[aria-label="Options for Groceries by month"]')
        ->press('Edit')
        ->assertVisible('[aria-label="Options for Groceries by month"]')
        ->assertNoJavascriptErrors();
});

/**
 * A frameless row of five KPI tiles under a heading: taller than a 16rem row
 * once the tiles stack on a phone.
 */
function kpiRowWidgetSource(): string
{
    return <<<'TSX'
        import { useWidgetData } from '@whisper-money/widget-sdk';

        export default function KpiRow() {
            const { groceries } = useWidgetData();
            const tiles = ['Ingresos', 'Gastos', 'Ahorro', 'Inversión', 'Compras'];

            return (
                <div className="flex h-full flex-col gap-3">
                    <div>
                        <h3 className="text-sm font-medium">Resumen del mes</h3>
                        <p className="text-xs text-muted-foreground">Lo esencial de un vistazo</p>
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        {tiles.map((tile) => (
                            <div key={tile} className="flex h-24 flex-col justify-between rounded-xl border bg-card p-4">
                                <span className="text-xs text-muted-foreground">{tile}</span>
                                <span className="text-xl font-semibold tabular-nums">{groceries.data.length}</span>
                            </div>
                        ))}
                    </div>
                </div>
            );
        }
        TSX;
}

/** A chart with no height of its own: it takes the cell's. */
function fullHeightChartWidgetSource(): string
{
    return <<<'TSX'
        import { useWidgetData } from '@whisper-money/widget-sdk';
        import { Bar, BarChart, ResponsiveContainer } from 'recharts';

        export default function Purchases() {
            const { groceries } = useWidgetData();
            const rows = groceries.data.map((row) => ({ amount: Math.abs(row.amount) }));

            return (
                <div className="h-full p-4">
                    <ResponsiveContainer width="100%" height="100%">
                        <BarChart data={rows}>
                            <Bar dataKey="amount" fill="var(--color-chart-1)" />
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            );
        }
        TSX;
}

/**
 * The dashboard of dashboardWithUserWidget() with the KPI row (frameless, 1/2
 * and m) and the chart (1/2 and l) added to it.
 *
 * @return array{0: User, 1: WidgetDefinition, 2: WidgetDefinition}
 */
function dashboardWithTallWidget(): array
{
    [$user, $dashboard] = dashboardWithUserWidget();
    $service = app(WidgetDefinitionService::class);

    $kpis = $service->create($user, [...groceriesManifest(), 'name' => 'KPI row', 'frame' => 'none', 'size' => ['width' => '1/2', 'height' => 'm']], kpiRowWidgetSource(), WidgetOrigin::Mcp);
    $chart = $service->create($user, [...groceriesManifest(), 'name' => 'Purchases chart', 'size' => ['width' => '1/2', 'height' => 'l']], fullHeightChartWidgetSource(), WidgetOrigin::Mcp);
    $service->place($dashboard, $kpis);
    $service->place($dashboard, $chart);

    return [$user, $kpis, $chart];
}

/**
 * The height of each widget's cell, whether its content scrolls inside it,
 * and the height of the chart's drawing.
 *
 * @return array{kpis: float, kpisScroll: float, chart: float, chartSvg: float, netWorth: float}
 */
function widgetCellHeights($page, WidgetDefinition $kpis, WidgetDefinition $chart): array
{
    return $page->script(<<<JS
        (() => {
            const cell = (selector) => document.querySelector(`[data-testid="dashboard-grid"] > \${selector}`);
            const kpis = cell('[data-widget="user:{$kpis->id}"]');
            const frame = kpis.querySelector('.wm-widget');
            const chart = cell('[data-widget="user:{$chart->id}"]');

            return {
                kpis: kpis.getBoundingClientRect().height,
                kpisScroll: frame.scrollHeight - frame.clientHeight,
                chart: chart.getBoundingClientRect().height,
                chartSvg: chart.querySelector('svg.recharts-surface')?.getBoundingClientRect().height ?? 0,
                netWorth: cell('[data-widget="net-worth"]').getBoundingClientRect().height,
            };
        })()
    JS);
}

it('lets a tall widget grow with its content on a phone, never below its height', function (bool $dark) {
    [$user, $kpis, $chart] = dashboardWithTallWidget();
    actingAs($user);

    $page = visit('/dashboard')
        ->on()
        ->iPhoneSE();

    if ($dark) {
        $page = $page->inDarkMode();
    }

    $page = $page->navigate('/dashboard')
        ->assertSee('Resumen del mes')
        ->assertPresent("[data-widget=\"user:{$chart->id}\"] svg.recharts-surface");

    $heights = widgetCellHeights($page, $kpis, $chart);

    // 16rem is 256px: the KPI row outgrows it and shows whole, with no
    // scrollbar of its own; the large chart keeps two rows and the gap
    // (33.5rem, 536px) to draw in.
    expect($heights['kpis'])->toBeGreaterThan(256)
        ->and($heights['kpisScroll'])->toBeLessThanOrEqual(0)
        ->and(round($heights['chart']))->toEqual(536.0)
        ->and($heights['chartSvg'])->toBeGreaterThan(400)
        ->and($heights['netWorth'])->toBeGreaterThanOrEqual(256);

    $page->assertNoJavascriptErrors();
})->with(['light' => false, 'dark' => true]);

it('gives the widgets of a wider dashboard\'s row one height, the tallest one\'s', function () {
    [$user, $kpis, $chart] = dashboardWithTallWidget();
    actingAs($user);

    $page = visit('/dashboard')
        ->assertSee('Resumen del mes')
        ->assertPresent("[data-widget=\"user:{$chart->id}\"] svg.recharts-surface");

    $heights = widgetCellHeights($page, $kpis, $chart);

    // The medium KPI row shares its row with the large chart: both are
    // 33.5rem (536px), and the KPI row fits whole in it. The net worth
    // chart and the groceries widget, both medium, keep 16rem (256px).
    expect(round($heights['kpis']))->toEqual(536.0)
        ->and($heights['kpisScroll'])->toBeLessThanOrEqual(0)
        ->and(round($heights['chart']))->toEqual(536.0)
        ->and(round($heights['netWorth']))->toEqual(256.0);

    $page->assertNoJavascriptErrors();
});

/**
 * A dashboard of the user's with the net worth chart (1/2, m), the groceries
 * widget at the auto height beside it (1/2, a), and a copy of it alone on the
 * next row (1/1, a).
 *
 * @return array{0: User, 1: WidgetDefinition, 2: WidgetDefinition}
 */
function dashboardWithAutoHeightWidgets(): array
{
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate(CustomDashboards::class);

    $account = Account::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'transaction_date' => '2026-09-03', 'amount' => -4210]);

    $dashboard = Dashboard::factory()->for($user)->create(['name' => 'Mine']);
    $user->forceFill(['default_dashboard_id' => $dashboard->id])->save();
    DashboardWidget::factory()->for($dashboard)->system(SystemWidget::NetWorth)->create(['position' => 0, 'width' => '1/2', 'height' => 'm']);

    $service = app(WidgetDefinitionService::class);
    $beside = $service->create($user, [...groceriesManifest(), 'size' => ['width' => '1/2', 'height' => 'a']], groceriesWidgetSource(), WidgetOrigin::Mcp);
    $alone = $service->create($user, [...groceriesManifest(), 'name' => 'Groceries alone', 'size' => ['width' => '1/1', 'height' => 'a']], groceriesWidgetSource(), WidgetOrigin::Mcp);
    $service->place($dashboard, $beside);
    $service->place($dashboard, $alone);

    return [$user, $beside, $alone];
}

/**
 * The height of each cell, and of what the widget alone draws in it.
 *
 * @return array{netWorth: float, beside: float, alone: float, aloneContent: float, aloneScroll: float}
 */
function autoHeightCells($page, WidgetDefinition $beside, WidgetDefinition $alone): array
{
    return $page->script(<<<JS
        (() => {
            const cell = (selector) => document.querySelector(`[data-testid="dashboard-grid"] > \${selector}`);
            const alone = cell('[data-widget="user:{$alone->id}"]');
            const frame = alone.querySelector('.wm-widget');

            return {
                netWorth: cell('[data-widget="net-worth"]').getBoundingClientRect().height,
                beside: cell('[data-widget="user:{$beside->id}"]').getBoundingClientRect().height,
                alone: alone.getBoundingClientRect().height,
                aloneContent: frame.firstElementChild.getBoundingClientRect().height,
                aloneScroll: frame.scrollHeight - frame.clientHeight,
            };
        })()
    JS);
}

it('sizes an auto widget to its content, or to the row it shares on a wider dashboard', function (bool $dark) {
    [$user, $beside, $alone] = dashboardWithAutoHeightWidgets();
    actingAs($user);

    $page = visit('/dashboard');

    if ($dark) {
        $page = $page->inDarkMode();
    }

    $page = $page->navigate('/dashboard')
        ->assertSee('Supermercado este año')
        ->assertPresent("[data-widget=\"user:{$alone->id}\"] [data-slot=user-widget][data-status=ready]");

    $heights = autoHeightCells($page, $beside, $alone);

    // Beside the medium net worth chart the short widget stretches to its
    // 16rem (256px); alone in its row it is just as tall as what it draws,
    // its card's border included.
    expect(round($heights['netWorth']))->toEqual(256.0)
        ->and(round($heights['beside']))->toEqual(256.0)
        ->and($heights['alone'])->toBeLessThan(200)
        ->and($heights['alone'] - $heights['aloneContent'])->toBeLessThanOrEqual(2.0)
        ->and($heights['aloneScroll'])->toBeLessThanOrEqual(0);

    $page->assertNoJavascriptErrors();
})->with(['light' => false, 'dark' => true]);

it('sizes an auto widget to its content on a phone', function () {
    [$user, $beside, $alone] = dashboardWithAutoHeightWidgets();
    actingAs($user);

    $page = visit('/dashboard')
        ->on()
        ->iPhoneSE()
        ->navigate('/dashboard')
        ->assertSee('Supermercado este año')
        ->assertPresent("[data-widget=\"user:{$beside->id}\"] [data-slot=user-widget][data-status=ready]");

    $heights = autoHeightCells($page, $beside, $alone);

    // On a phone each widget has its row to itself: the medium chart keeps
    // its 16rem, the auto ones take the height of their content.
    expect($heights['netWorth'])->toBeGreaterThanOrEqual(256)
        ->and($heights['beside'])->toBeLessThan(200)
        ->and(round($heights['beside']))->toEqual(round($heights['alone']))
        ->and($heights['alone'] - $heights['aloneContent'])->toBeLessThanOrEqual(2.0);

    $page->assertNoJavascriptErrors();
});

/**
 * The Panigale widget the SDK reference's example is written after: the rows
 * of one label through <TransactionList>, descriptions rewritten and the big
 * ones tagged, with a CSV button in its heading.
 *
 * @return array{0: User, 1: WidgetDefinition}
 */
function dashboardWithTransactionListWidget(): array
{
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    Feature::for($user)->activate(CustomDashboards::class);

    $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Nómina', 'currency_code' => 'EUR']);
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Moto', 'icon' => 'Car', 'color' => 'red']);
    $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Panigale']);

    foreach ([
        ['2026-10-03', 'Pago en Ducati Store', -64900],
        ['2026-10-03', 'Pago en Repsol', -3200],
        ['2026-10-11', 'Pago en Motoclub', -2500],
    ] as [$date, $description, $amount]) {
        Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $category->id,
            'transaction_date' => $date,
            'description' => $description,
            'amount' => $amount,
            'currency_code' => 'EUR',
        ])->labels()->attach($label);
    }

    // Not the label's: the widget never lists it.
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'description' => 'Mercadona', 'transaction_date' => '2026-10-04']);

    $dashboard = Dashboard::factory()->for($user)->create(['name' => 'Moto']);
    $user->forceFill(['default_dashboard_id' => $dashboard->id])->save();

    $service = app(WidgetDefinitionService::class);
    $widget = $service->create($user, [
        'name' => 'Panigale · Movimientos',
        'size' => ['width' => '1/2', 'height' => 'l'],
        'data' => ['tx' => ['endpoint' => 'GET /transactions', 'params' => ['label_ids' => [$label->id]], 'paginate' => 'all']],
    ], str_replace('<label id>', $label->id, <<<'TSX'
        import { Button, TransactionList, useWidgetData, useWidgetEnv, wm } from '@whisper-money/widget-sdk';
        import { Download } from 'lucide-react';

        export default function Panigale() {
            const { tx } = useWidgetData();
            const { isCompact } = useWidgetEnv();

            return (
                <div className="flex h-full flex-col">
                    <div className="flex items-center justify-between gap-2 px-4 py-3">
                        <h3 className="text-sm font-medium">Movimientos de la Panigale</h3>
                        <Button variant="outline" size="sm" onClick={() => wm.download('panigale', tx.data)}>
                            <Download size={14} />
                            CSV
                        </Button>
                    </div>
                    <TransactionList
                        transactions={tx}
                        description={(row) => row.description.replace('Pago en ', '')}
                        badge={(row) => (row.amount <= -50000 ? 'Compra' : null)}
                        hide={isCompact ? ['notes'] : []}
                        hiddenLabelId="<label id>"
                    />
                </div>
            );
        }
        TSX), WidgetOrigin::Mcp);
    $service->place($dashboard, $widget);

    return [$user, $widget];
}

it('lists transactions in a user widget the way the app does', function (bool $dark) {
    [$user, $widget] = dashboardWithTransactionListWidget();
    actingAs($user);

    $page = visit('/dashboard');

    if ($dark) {
        $page = $page->inDarkMode();
    }

    $cell = userWidgetCell($widget);

    $page->assertSee('Movimientos de la Panigale')
        ->assertCount("{$cell} [data-testid=\"transaction-list-row\"]", 3)
        ->assertCount("{$cell} [data-testid=\"transaction-day-header\"]", 2)
        ->assertSeeIn($cell, 'Ducati Store')
        ->assertDontSeeIn($cell, 'Pago en')
        ->assertDontSeeIn($cell, 'Mercadona')
        ->assertSeeIn("{$cell} [data-testid=\"transaction-list-row-badge\"]", 'Compra')
        ->assertSeeIn($cell, 'Moto')
        ->assertSeeIn($cell, 'Nómina')
        // The label every row carries is left out of each.
        ->assertDontSeeIn("{$cell} [data-testid=\"transaction-list-row\"]", 'Panigale')
        ->assertMissing("{$cell} [role=\"alert\"]")
        ->assertNoJavascriptErrors();
})->with(['light' => false, 'dark' => true]);
