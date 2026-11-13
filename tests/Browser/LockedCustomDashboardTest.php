<?php

declare(strict_types=1);

use App\Enums\SystemWidget;
use App\Features\CustomDashboards;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use App\Models\WidgetDefinition;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * A user whose Standard plan ended opens a dashboard they built while on it:
 * placeholders at each widget's size, blurred, under a notice to upgrade, and
 * nothing of the widgets themselves on the page.
 */
beforeEach(function (): void {
    config(['subscriptions.enabled' => true]);
    fakeCurrencyApi();
});

/**
 * @return array{0: User, 1: Dashboard}
 */
function lapsedUserWithDashboard(): array
{
    $user = User::factory()->onboarded()->formerSubscriber()->create(['paywall_seen_at' => now()]);
    Feature::for($user)->activate(CustomDashboards::class);
    $widget = WidgetDefinition::factory()->for($user)->create(['name' => 'Groceries by month']);
    $dashboard = Dashboard::factory()->for($user)->create(['name' => 'Mine']);
    DashboardWidget::factory()->for($dashboard)->system(SystemWidget::NetWorth)->create(['position' => 0, 'width' => '1/2', 'height' => 'l']);
    DashboardWidget::factory()->for($dashboard)->create(['system_widget' => null, 'widget_definition_id' => $widget->id, 'position' => 1, 'width' => '1/2', 'height' => 'm']);

    return [$user, $dashboard];
}

it('draws a dashboard off the plan as blurred placeholders under a notice to upgrade', function (bool $dark) {
    [$user, $dashboard] = lapsedUserWithDashboard();

    actingAs($user);

    $page = visit('/dashboard?dashboard='.$dashboard->id);

    if ($dark) {
        $page = $page->inDarkMode();
    }

    $page->assertSee('Your dashboard is waiting for you')
        ->assertSee('See plans')
        ->assertCount('[data-widget-placeholder]', 2)
        ->assertMissing('[data-testid="dashboard-grid"]')
        ->assertDontSee('Groceries by month')
        ->assertDontSee('Net Worth Evolution')
        ->assertMissing('button:has-text("Edit")')
        ->assertNoJavascriptErrors();
})->with(['light' => false, 'dark' => true]);

it('fits the notice and the placeholders on a phone', function () {
    [$user, $dashboard] = lapsedUserWithDashboard();

    actingAs($user);

    $page = visit('/dashboard')
        ->on()
        ->iPhoneSE()
        ->navigate('/dashboard?dashboard='.$dashboard->id)
        ->assertSee('Your dashboard is waiting for you');

    $overflow = $page->script(<<<'JS'
        (() => {
            const main = document.querySelector('[data-slot="sidebar-inset"]');

            return main.scrollWidth - main.clientWidth;
        })()
    JS);

    expect($overflow)->toBeLessThanOrEqual(0);

    $page->assertNoJavascriptErrors();
});

it('tells a user off the plan that new dashboards are part of Standard', function () {
    [$user] = lapsedUserWithDashboard();

    actingAs($user);

    visit('/dashboard')
        ->click('[aria-label="Switch dashboard"]')
        ->click('New dashboard')
        ->assertSee('is part of the Standard plan')
        ->assertSee('See plans')
        ->assertMissing('#dashboard-name')
        ->assertNoJavascriptErrors();
});

it('shows the AI dashboard guide off the plan under a Standard plan banner', function () {
    [$user] = lapsedUserWithDashboard();

    actingAs($user);

    visit('/dashboard')
        ->click('[aria-label="Switch dashboard"]')
        ->click('Create a dashboard with AI')
        ->assertVisible('[data-testid="ai-dashboard-guide"]')
        ->assertVisible('[data-testid="ai-dashboard-guide-plan-banner"]')
        ->assertSee("It's a Standard plan feature")
        ->assertSee('Connect your assistant')
        ->assertSee('See plans')
        ->assertNoJavascriptErrors();
});

it('opens the AI dashboard guide as a bottom drawer on a phone', function () {
    [$user] = lapsedUserWithDashboard();

    actingAs($user);

    visit('/dashboard')
        ->on()
        ->iPhoneSE()
        ->navigate('/dashboard')
        ->click('[aria-label="Switch dashboard"]')
        ->click('Create a dashboard with AI')
        ->assertVisible('[data-testid="ai-dashboard-guide"][data-vaul-drawer-direction="bottom"]')
        ->assertVisible('[data-testid="ai-dashboard-guide-plan-banner"]')
        ->assertNoJavascriptErrors();
});
