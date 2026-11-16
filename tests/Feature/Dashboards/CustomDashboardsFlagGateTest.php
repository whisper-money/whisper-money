<?php

use App\Features\CustomDashboards;
use App\Mcp\Resources\WidgetPreview;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\Concerns\GuardsCustomDashboards;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use App\Models\WidgetDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Feature;

use function Pest\Laravel\withHeaders;

/**
 * The CustomDashboards flag (off by default) gates every server surface of
 * custom dashboards and widgets, not only the UI. These tests enumerate the
 * surfaces instead of listing them, so a route or an MCP tool added later
 * without the gate fails here.
 */

/**
 * Every route served by a controller of the dashboards or widgets namespaces.
 *
 * @return list<RoutingRoute>
 */
function customDashboardRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getControllerClass(), 'App\\Http\\Controllers\\Dashboards\\')
            || str_starts_with((string) $route->getControllerClass(), 'App\\Http\\Controllers\\Widgets\\'))
        ->values()
        ->all();
}

/**
 * The classes of the MCP primitives the CustomDashboards flag gates: every
 * tool or resource using GuardsCustomDashboards, plus the preview app.
 *
 * @return array{tools: list<class-string>, resources: list<class-string>}
 */
function customDashboardPrimitives(): array
{
    $defaults = (new ReflectionClass(WhisperMoneyServer::class))->getDefaultProperties();
    $guarded = fn (string $class): bool => in_array(GuardsCustomDashboards::class, class_uses_recursive($class), true);

    return [
        'tools' => array_values(array_filter($defaults['tools'], $guarded)),
        'resources' => array_values(array_unique([...array_filter($defaults['resources'], $guarded), WidgetPreview::class])),
    ];
}

/**
 * @return array<string, mixed>
 */
function listedOverMcp(User $user, string $method, string $key): array
{
    $token = $user->createToken('mcp', ['mcp:read', 'mcp:write'])->plainTextToken;
    app('auth')->forgetGuards();

    return withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method])
        ->assertOk()
        ->json("result.{$key}");
}

describe('web routes', function () {
    it('finds the routes it guards', function () {
        expect(collect(customDashboardRoutes())->map(fn (RoutingRoute $route): ?string => $route->getName())->all())
            ->toContain('dashboards.store', 'dashboards.update', 'dashboards.default.update', 'dashboards.widgets.store', 'dashboards.widgets.data', 'widgets.data', 'widgets.errors.store', 'widgets.revert');
    });

    it('refuses every dashboards and widgets route to a verified user without the flag', function () {
        $user = User::factory()->onboarded()->create();
        $dashboard = Dashboard::factory()->for($user)->create();
        $definition = WidgetDefinition::factory()->for($user)->create();
        $models = [
            Dashboard::class => $dashboard,
            WidgetDefinition::class => $definition,
            DashboardWidget::class => DashboardWidget::factory()->for($dashboard)->create(['system_widget' => null, 'widget_definition_id' => $definition->id]),
        ];

        foreach (customDashboardRoutes() as $route) {
            $parameters = collect($route->signatureParameters(['subClass' => Model::class]))
                ->mapWithKeys(fn (ReflectionParameter $parameter): array => [
                    $parameter->getName() => $models[$parameter->getType()?->getName()] ?? throw new LogicException("No model for {$route->uri()} {{$parameter->getName()}}: add one to this test."),
                ])
                ->all();
            $method = collect($route->methods())->reject(fn (string $verb): bool => $verb === 'HEAD')->first();
            $status = $this->actingAs($user)->json($method, route($route->getName(), $parameters), [
                'name' => 'Spending',
                'revision' => 1,
                'phase' => 'render',
                'message' => 'x',
                'ids' => [],
            ])->status();

            expect($status)->toBeIn([403, 404], "{$method} {$route->uri()} answered {$status} without the flag");
        }
    });

    it('keeps /dashboard open, as the classic dashboard', function () {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('dashboard')->missing('dashboards')->missing('selectedDashboard'));
    });
});

describe('MCP', function () {
    it('finds the primitives it guards', function () {
        $primitives = customDashboardPrimitives();

        expect(count($primitives['tools']))->toBeGreaterThanOrEqual(8)
            ->and($primitives['resources'])->toContain(WidgetPreview::class);
    });

    it('neither lists nor serves them without the flag', function () {
        $user = User::factory()->create();
        $primitives = customDashboardPrimitives();
        $toolNames = array_map(fn (string $class): string => app($class)->name(), $primitives['tools']);
        $resourceUris = array_map(fn (string $class): string => app($class)->uri(), $primitives['resources']);

        expect(collect(listedOverMcp($user, 'tools/list', 'tools'))->pluck('name')->intersect($toolNames)->all())->toBe([])
            ->and(collect(listedOverMcp($user, 'resources/list', 'resources'))->pluck('uri')->intersect($resourceUris)->all())->toBe([]);

        $user->withAccessToken($user->createToken('mcp', ['mcp:read', 'mcp:write'])->accessToken);

        foreach ($primitives['tools'] as $tool) {
            WhisperMoneyServer::actingAs($user)->tool($tool, [])
                ->assertHasErrors(['Tool ['.app($tool)->name().'] not found.']);
        }

        foreach ($primitives['resources'] as $resource) {
            WhisperMoneyServer::actingAs($user)->resource($resource)
                ->assertHasErrors(['Resource ['.app($resource)->uri().'] not found.']);
        }
    });

    it('lists them all once the flag is on', function () {
        $user = User::factory()->create();
        Feature::for($user)->activate(CustomDashboards::class);
        $primitives = customDashboardPrimitives();

        expect(collect(listedOverMcp($user, 'tools/list', 'tools'))->pluck('name')->all())
            ->toContain(...array_map(fn (string $class): string => app($class)->name(), $primitives['tools']));
    });
});
