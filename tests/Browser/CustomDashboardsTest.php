<?php

declare(strict_types=1);

use App\Enums\SystemWidget;
use App\Enums\WidgetWidth;
use App\Features\CustomDashboards;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * The dashboard picker and the 12-column grid of the user's own dashboards,
 * which only show behind the CustomDashboards flag.
 */
beforeEach(function (): void {
    // With subscriptions off every user has the paid plan.
    config(['subscriptions.enabled' => false]);
    fakeCurrencyApi();
});

/**
 * Drags one widget by its handle onto another. Playwright's drag moves the
 * pointer once, and dnd-kit only starts a drag after it has moved a few
 * pixels, so the pointer walks there in steps instead.
 */
function dragWidget($page, string $from, string $to): void
{
    $page->script(<<<JS
        (async () => {
            const handle = document.querySelector('[data-widget="{$from}"] [aria-label="Drag to reorder"]');
            const target = document.querySelector('[data-widget="{$to}"]').getBoundingClientRect();
            const start = handle.getBoundingClientRect();
            const from = { x: start.x + start.width / 2, y: start.y + start.height / 2 };
            const to = { x: target.x + 24, y: target.y + 24 };
            const fire = (type, point, on = document) => on.dispatchEvent(new PointerEvent(type, {
                bubbles: true, cancelable: true, isPrimary: true, pointerId: 1, button: 0, buttons: 1,
                clientX: point.x, clientY: point.y,
            }));
            const pause = () => new Promise((resolve) => setTimeout(resolve, 16));

            fire('pointerdown', from, handle);
            for (let step = 1; step <= 20; step++) {
                fire('pointermove', {
                    x: from.x + ((to.x - from.x) * step) / 20,
                    y: from.y + ((to.y - from.y) * step) / 20,
                });
                await pause();
            }
            fire('pointerup', to);
        })()
    JS);
}

it('looks the same as before with the flag off', function () {
    $user = User::factory()->onboarded()->create();
    // Even a default dashboard of their own (set by an agent, say) is ignored.
    $dashboard = Dashboard::factory()->for($user)->create(['name' => 'Agent board']);
    $user->forceFill(['default_dashboard_id' => $dashboard->id])->save();

    actingAs($user);

    visit('/dashboard')
        ->assertSee('Overview of your financial health')
        ->assertSee('Net Worth Evolution')
        ->assertMissing('[aria-label="Switch dashboard"]')
        ->assertDontSee('Agent board')
        ->assertDontSee('This dashboard is empty')
        ->assertNoJavascriptErrors();
});

it('builds two dashboards, edits their widgets and picks the default one', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate(CustomDashboards::class);

    actingAs($user);

    $page = visit('/dashboard')
        ->assertSee('Net Worth Evolution')
        ->click('[aria-label="Switch dashboard"]')
        ->click('New dashboard')
        ->fill('name', 'Monthly review')
        ->press('Create')
        ->assertSee('This dashboard is empty');

    $monthly = Dashboard::query()->where('name', 'Monthly review')->sole();
    expect($user->dashboards()->count())->toBe(1);

    // The empty state adds the first widget and opens edit mode.
    $page->press('Add widget')
        ->click('[role="menuitem"]:has-text("Top labels")')
        ->assertSee('Done')
        ->assertPresent('[aria-label="Resize Top labels"]');

    $page->wait(0.5)->press('Add widget')
        ->click('[role="menuitem"]:has-text("Cashflow")')
        ->assertPresent('[aria-label="Resize Cashflow"]');

    $page->wait(0.5)->press('Add widget')
        ->click('[role="menuitem"]:has-text("Net Worth Evolution")')
        ->assertPresent('[aria-label="Resize Net Worth Evolution"]');

    expect($monthly->widgets()->orderBy('position')->pluck('system_widget')->map->value->all())
        ->toBe(['top-labels', 'cashflow', 'net-worth']);

    // Only the sizes the widget admits are offered: cashflow has one height.
    $page->click('[aria-label="Resize Cashflow"]')
        ->assertSee('Width')
        ->assertDontSee('Height')
        ->assertDontSee('A third')
        ->click('[role="menuitemradio"]:has-text("Half")');

    $page->wait(0.5);
    expect($monthly->widgets()->where('system_widget', SystemWidget::Cashflow)->sole()->width)
        ->toBe(WidgetWidth::Half);

    // Drag net worth to the front.
    dragWidget($page, 'net-worth', 'top-labels');
    $page->wait(0.5);

    expect($monthly->widgets()->orderBy('position')->pluck('system_widget')->map->value->first())
        ->toBe('net-worth');

    // Removing is optimistic and sticks.
    $page->click('[aria-label="Remove Top labels"]')
        ->assertMissing('[aria-label="Resize Top labels"]');
    $page->wait(0.5);
    expect($monthly->widgets()->count())->toBe(2);

    $page->press('Done')
        ->assertMissing('[aria-label="Resize Cashflow"]')
        ->assertPresent('[data-testid="dashboard-grid"] [data-widget="net-worth"]');

    // A second dashboard, then make the first one the default.
    $page->click('[aria-label="Switch dashboard"]')
        ->click('New dashboard')
        ->fill('name', 'Spending')
        ->press('Create')
        ->assertSee('This dashboard is empty')
        ->click('[aria-label="Switch dashboard"]')
        ->click('Manage dashboards')
        ->click('[aria-label="Make Monthly review the default dashboard"]')
        ->assertPresent('[aria-label="Default dashboard"]');

    $page->wait(0.5);
    expect($user->fresh()->default_dashboard_id)->toBe($monthly->id)
        ->and($user->dashboards()->count())->toBe(2);

    // The app now opens on it, and the system dashboard is still a click away.
    // The browser's requests run in this process on this same user, whose
    // relation still holds the default it had before.
    $user->unsetRelation('defaultDashboard');

    visit('/dashboard')
        ->assertPresent('[data-testid="dashboard-grid"] [data-widget="net-worth"]')
        ->assertSee('Monthly review')
        ->click('[aria-label="Switch dashboard"]')
        ->click('[role="menuitem"]:has-text("Overview")')
        ->assertSee('Overview of your financial health')
        ->assertMissing('[data-testid="dashboard-grid"]')
        ->assertNoJavascriptErrors();
});

it('reorders widgets with the keyboard', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate(CustomDashboards::class);
    $dashboard = Dashboard::factory()->for($user)->create();
    $first = DashboardWidget::factory()->for($dashboard)->system(SystemWidget::TopCategories)->create(['position' => 0]);
    DashboardWidget::factory()->for($dashboard)->system(SystemWidget::NetWorth)->create(['position' => 1]);

    actingAs($user);

    $page = visit('/dashboard?dashboard='.$dashboard->id)
        ->press('Edit');

    $page->script(<<<'JS'
        document.querySelector('[data-widget="top-categories"] [aria-label="Drag to reorder"]').focus()
    JS);
    $page->keys('[data-widget="top-categories"] [aria-label="Drag to reorder"]', ['Space'])
        ->wait(0.2)
        ->keys('[data-widget="top-categories"] [aria-label="Drag to reorder"]', ['ArrowDown'])
        ->wait(0.2)
        ->keys('[data-widget="top-categories"] [aria-label="Drag to reorder"]', ['Space'])
        ->wait(0.5);

    expect($first->fresh()->position)->toBe(1);
});

it('fits a phone: every widget full width, keeping its height', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate(CustomDashboards::class);
    $dashboard = Dashboard::factory()->for($user)->create();
    $user->forceFill(['default_dashboard_id' => $dashboard->id])->save();
    DashboardWidget::factory()->for($dashboard)->system(SystemWidget::NetWorth)->create(['position' => 0, 'width' => '1/2', 'height' => 'l']);
    DashboardWidget::factory()->for($dashboard)->system(SystemWidget::TopCategories)->create(['position' => 1, 'width' => '1/2', 'height' => 'm']);

    actingAs($user);

    $page = visit('/dashboard')
        ->on()
        ->iPhoneSE()
        ->navigate('/dashboard')
        ->assertPresent('[data-testid="dashboard-grid"] [data-widget="net-worth"]');

    $layout = $page->script(<<<'JS'
        (() => {
            const grid = document.querySelector('[data-testid="dashboard-grid"]');
            const cell = (id) => grid.querySelector(`[data-widget="${id}"]`).getBoundingClientRect();
            const main = document.querySelector('[data-slot="sidebar-inset"]');

            return {
                grid: grid.getBoundingClientRect().width,
                netWorth: cell('net-worth'),
                topCategories: cell('top-categories'),
                overflow: main.scrollWidth - main.clientWidth,
            };
        })()
    JS);

    expect($layout['overflow'])->toBeLessThanOrEqual(0)
        ->and($layout['netWorth']['width'])->toEqual($layout['grid'])
        ->and($layout['topCategories']['width'])->toEqual($layout['grid'])
        // L is two M rows plus the gap between them.
        ->and($layout['netWorth']['height'])->toBeGreaterThan($layout['topCategories']['height'] * 2);

    $page->assertNoJavascriptErrors();
});

it('opens the guide to building a dashboard with AI from the picker', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate(CustomDashboards::class);

    actingAs($user);

    visit('/dashboard')
        ->click('[aria-label="Switch dashboard"]')
        ->click('Create a dashboard with AI')
        ->assertVisible('[data-testid="ai-dashboard-guide"]')
        ->assertSee('Connect your assistant')
        ->assertSee('Ask for your dashboard')
        ->assertSee('Open it here')
        ->assertSee('Open AI Connector')
        ->assertMissing('[data-testid="ai-dashboard-guide-plan-banner"]')
        ->assertNoJavascriptErrors();
});
