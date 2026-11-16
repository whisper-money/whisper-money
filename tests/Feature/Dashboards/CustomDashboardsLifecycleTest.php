<?php

use App\Enums\SystemWidget;
use App\Enums\WidgetOrigin;
use App\Features\CustomDashboards;
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
use Laravel\Pennant\Feature;

use function Pest\Laravel\withHeaders;

/**
 * What happens to custom dashboards when the CustomDashboards flag is turned
 * off for a user who built some, turned on again, or the account goes. This
 * instance has no plans, so the flag going off is the fork's equivalent of
 * upstream's plan lapsing: `/dashboard` draws the classic dashboard, no data
 * and no widget code is served, the agent sees no dashboard tool, and nothing
 * is deleted.
 */

/**
 * A user with the flag who built a dashboard holding a system widget and one
 * their agent wrote, made it their default, and then lost the flag.
 *
 * @return array{0: User, 1: Dashboard, 2: WidgetDefinition}
 */
function flaggedOffDashboardOwner(): array
{
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate(CustomDashboards::class);
    $widget = WidgetDefinition::factory()->for($user)->create();
    $dashboard = Dashboard::factory()->for($user)->create();
    DashboardWidget::factory()->for($dashboard)->system(SystemWidget::Cashflow)->create(['position' => 0, 'width' => '1/3', 'height' => 'm']);
    DashboardWidget::factory()->for($dashboard)->create(['system_widget' => null, 'widget_definition_id' => $widget->id, 'position' => 1, 'width' => '1/2', 'height' => 'l']);
    $user->forceFill(['default_dashboard_id' => $dashboard->id])->save();
    Feature::for($user)->deactivate(CustomDashboards::class);

    return [$user, $dashboard, $widget];
}

/**
 * The names of the MCP tools listed to $user over HTTP.
 *
 * @return list<string>
 */
function listedMcpToolNames(User $user): array
{
    $token = $user->createToken('mcp', ['mcp:read', 'mcp:write'])->plainTextToken;
    app('auth')->forgetGuards();

    return collect(withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertOk()
        ->json('result.tools'))->pluck('name')->all();
}

describe('with the flag turned off', function () {
    it('serves no widget data, for one widget or the whole dashboard', function () {
        [$user, $dashboard, $widget] = flaggedOffDashboardOwner();

        $this->actingAs($user)->getJson(route('widgets.data', $widget))->assertForbidden();
        $this->actingAs($user)->getJson(route('dashboards.widgets.data', $dashboard))->assertForbidden();
    });

    it('answers a widget that is not the user\'s the same way, so nothing tells it exists', function () {
        [$user] = flaggedOffDashboardOwner();

        $this->actingAs($user)
            ->getJson(route('widgets.data', WidgetDefinition::factory()->create()))
            ->assertForbidden();
    });

    it('draws the classic dashboard, whatever default is stored or dashboard is asked for, with no widget code', function (bool $askForIt) {
        [$user, $dashboard, $widget] = flaggedOffDashboardOwner();

        $response = $this->actingAs($user)->get(route('dashboard', $askForIt ? ['dashboard' => $dashboard->id] : []));

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboard')
                ->where('features.customDashboards', false)
                ->missing('dashboards')
                ->missing('defaultDashboardId')
                ->missing('selectedDashboard')
                ->loadDeferredProps('dashboard', fn (AssertableInertia $reload) => $reload
                    ->has('netWorthEvolution')
                    ->has('netWorthProjection')
                    ->has('topCategories')
                    ->has('cashflowSummary')
                    ->missing('userWidgets')
                    ->missing('widgetDefinitions')));

        expect($response->getContent())->not->toContain($widget->compiled_js);
    })->with(['the default' => false, 'the dashboard by id' => true]);

    it('lists no dashboard tool to the agent and runs none', function () {
        [$user] = flaggedOffDashboardOwner();

        expect(listedMcpToolNames($user))->toContain('list_accounts')
            ->not->toContain('list_dashboards', 'create_dashboard', 'create_widget', 'get_widget');

        $user->withAccessToken($user->createToken('mcp', ['mcp:read', 'mcp:write'])->accessToken);
        WhisperMoneyServer::actingAs($user)->tool(CreateDashboard::class, ['name' => 'Spending'])
            ->assertHasErrors(['Tool [create_dashboard] not found.']);
        WhisperMoneyServer::actingAs($user)->tool(CreateWidget::class, ['manifest' => [], 'source' => ''])
            ->assertHasErrors(['Tool [create_widget] not found.']);
    });

    it('lets nobody create a dashboard, and deletes nothing that was built', function () {
        [$user, $dashboard, $widget] = flaggedOffDashboardOwner();

        $this->actingAs($user)->post(route('dashboards.store'), ['name' => 'Spending'])->assertForbidden();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        expect($user->dashboards()->pluck('id')->all())->toBe([$dashboard->id])
            ->and($dashboard->widgets()->count())->toBe(2)
            ->and($user->widgetDefinitions()->pluck('id')->all())->toBe([$widget->id])
            ->and($widget->revisions()->count())->toBe(1)
            ->and($user->fresh()->default_dashboard_id)->toBe($dashboard->id);
    });
});

it('brings everything back as it was once the flag is on again', function () {
    [$user, $dashboard, $widget] = flaggedOffDashboardOwner();
    Feature::for($user)->activate(CustomDashboards::class);

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
    [$user, $dashboard, $widget] = flaggedOffDashboardOwner();
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
        $user = User::factory()->onboarded()->create();
        Feature::for($user)->activate(CustomDashboards::class);
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
