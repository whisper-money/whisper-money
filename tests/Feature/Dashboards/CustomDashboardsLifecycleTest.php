<?php

use App\Enums\SystemWidget;
use App\Enums\WidgetOrigin;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CreateDashboard;
use App\Mcp\Tools\CreateWidget;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Models\WidgetRevision;
use App\Services\Dashboards\DashboardService;
use App\Services\Widgets\WidgetDefinitionService;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;

/**
 * What happens to custom dashboards when the paid plan ends, comes back, or
 * the account goes: they are kept off the plan but drawn as placeholders,
 * with no data and no widget code served, and they go with the account.
 */
beforeEach(function () {
    config(['subscriptions.enabled' => true]);
});

/**
 * A user whose Standard plan ended, with a dashboard holding a system widget
 * and one their agent wrote, which was their default.
 *
 * @return array{0: User, 1: Dashboard, 2: WidgetDefinition}
 */
function lapsedDashboardOwner(): array
{
    $user = User::factory()->onboarded()->formerSubscriber()->create(['paywall_seen_at' => now()]);
    $widget = WidgetDefinition::factory()->for($user)->create();
    $dashboard = Dashboard::factory()->for($user)->create();
    DashboardWidget::factory()->for($dashboard)->system(SystemWidget::Cashflow)->create(['position' => 0, 'width' => '1/3', 'height' => 'm']);
    DashboardWidget::factory()->for($dashboard)->create(['system_widget' => null, 'widget_definition_id' => $widget->id, 'position' => 1, 'width' => '1/2', 'height' => 'l']);
    $user->forceFill(['default_dashboard_id' => $dashboard->id])->save();

    return [$user, $dashboard, $widget];
}

function subscribeAgain(User $user): User
{
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_again',
        'stripe_price' => 'price_factory',
        'stripe_status' => 'active',
    ]);

    return $user->refresh();
}

describe('off the paid plan', function () {
    it('serves no widget data, for one widget or the whole dashboard', function () {
        [$user, $dashboard, $widget] = lapsedDashboardOwner();

        $this->actingAs($user)->getJson(route('widgets.data', $widget))->assertForbidden();
        $this->actingAs($user)->getJson(route('dashboards.widgets.data', $dashboard))->assertForbidden();
    });

    it('still answers 404 for a widget that is not the user\'s', function () {
        [$user] = lapsedDashboardOwner();

        $this->actingAs($user)
            ->getJson(route('widgets.data', WidgetDefinition::factory()->create()))
            ->assertNotFound();
    });

    it('opens the system dashboard by default and the user\'s own one on request, with its layout and no widget code', function () {
        [$user, $dashboard, $widget] = lapsedDashboardOwner();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDashboard', null)
                ->where('defaultDashboardId', null));

        $response = $this->actingAs($user)->get(route('dashboard', ['dashboard' => $dashboard->id]));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selectedDashboard.id', $dashboard->id)
            ->has('selectedDashboard.widgets', 2)
            ->where('selectedDashboard.widgets.1.width', '1/2')
            ->where('selectedDashboard.widgets.1.height', 'l')
            ->loadDeferredProps('dashboard', fn (AssertableInertia $reload) => $reload
                ->where('userWidgets', [])
                ->where('widgetDefinitions', [])
            ));

        expect($response->getContent())->not->toContain($widget->compiled_js);
    });

    it('lets neither the web nor the agent create a dashboard or a widget', function () {
        [$user] = lapsedDashboardOwner();

        $this->actingAs($user)->post(route('dashboards.store'), ['name' => 'Spending'])->assertForbidden();

        $user->withAccessToken($user->createToken('mcp', ['mcp:read', 'mcp:write'])->accessToken);
        WhisperMoneyServer::actingAs($user)->tool(CreateDashboard::class, ['name' => 'Spending'])
            ->assertHasErrors()
            ->assertSee('part of the paid Standard plan');
        WhisperMoneyServer::actingAs($user)->tool(CreateWidget::class, ['manifest' => [], 'source' => ''])
            ->assertHasErrors()
            ->assertSee('part of the paid Standard plan');

        expect($user->dashboards()->count())->toBe(1)
            ->and($user->widgetDefinitions()->count())->toBe(1);
    });
});

it('brings everything back as it was once the user pays again', function () {
    [$user, $dashboard, $widget] = lapsedDashboardOwner();
    $user = subscribeAgain($user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selectedDashboard.id', $dashboard->id)
            ->where('defaultDashboardId', $dashboard->id)
            ->has('selectedDashboard.widgets', 2)
            ->loadDeferredProps('dashboard', fn (AssertableInertia $reload) => $reload
                ->where("userWidgets.{$widget->id}.js", $widget->compiled_js)
            ));

    $this->actingAs($user)->getJson(route('widgets.data', $widget))->assertOk();
    $this->actingAs($user)->getJson(route('dashboards.widgets.data', $dashboard))->assertOk();
});

it('purges dashboards, placements, widgets and their revisions when the account is deleted', function () {
    [$user, $dashboard, $widget] = lapsedDashboardOwner();
    $other = WidgetDefinition::factory()->create();

    expect(WidgetRevision::query()->where('widget_definition_id', $widget->id)->exists())->toBeTrue();

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    expect(Dashboard::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DashboardWidget::query()->where('dashboard_id', $dashboard->id)->exists())->toBeFalse()
        ->and(WidgetDefinition::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(WidgetRevision::query()->where('widget_definition_id', $widget->id)->exists())->toBeFalse()
        ->and(WidgetDefinition::find($other->id))->not->toBeNull();
});

describe('metrics', function () {
    it('logs where a dashboard was created from, never its name', function (WidgetOrigin $origin) {
        Log::spy();

        app(DashboardService::class)->create(User::factory()->create(), 'My secret plans', $origin);

        Log::shouldHaveReceived('info')->once()->with('Custom dashboard created', ['source' => $origin->value]);
    })->with([WidgetOrigin::Web, WidgetOrigin::Mcp]);

    it('logs how a widget was saved, never its name or code', function () {
        $widget = WidgetDefinition::factory()->create();
        Log::spy();

        $service = app(WidgetDefinitionService::class);
        $service->update($widget, $widget->manifest, $widget->source, WidgetOrigin::Mcp);
        $service->revert($widget, 1, WidgetOrigin::Web);

        Log::shouldHaveReceived('info')->with('User widget saved', ['source' => 'mcp', 'action' => 'updated', 'revision' => 2]);
        Log::shouldHaveReceived('info')->with('User widget saved', ['source' => 'web', 'action' => 'reverted', 'revision' => 3]);
    });

    it('logs how a gateway read went, never what it read', function () {
        $user = User::factory()->onboarded()->subscribed()->create();
        $widget = WidgetDefinition::factory()->for($user)->create();
        Log::spy();

        $this->actingAs($user)->getJson(route('widgets.data', $widget))->assertOk();

        Log::shouldHaveReceived('info')->once()->with('User widget data read', [
            'source' => 'web',
            'widgets' => 1,
            'calls' => count($widget->manifest['data']),
            'failed_calls' => 0,
            'truncated_calls' => 0,
            'rate_limited_calls' => 0,
        ]);
    });
});
