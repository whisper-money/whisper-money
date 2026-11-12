<?php

declare(strict_types=1);

use App\Enums\SystemWidget;
use App\Enums\WidgetOrigin;
use App\Features\CustomDashboards;
use App\Models\Account;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
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

    // The menu is there without hovering: a phone has no pointer to hover with.
    $page->assertVisible('[aria-label="Options for Groceries by month"]')
        ->assertNoJavascriptErrors();
});
