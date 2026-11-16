<?php

use App\Enums\WidgetOrigin;
use App\Enums\WidgetRecheck;
use App\Features\CustomDashboards;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\GetWidget;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Services\Widgets\InvalidWidget;
use App\Services\Widgets\WidgetCompiler;
use App\Services\Widgets\WidgetDefinitionService;
use App\Services\Widgets\WidgetPreviewPayload;
use App\Services\Widgets\WidgetRules;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;

use function Pest\Laravel\artisan;

/**
 * Widgets stored under older widget rules are checked again before their
 * module is served: by `widgets:revalidate` at deploy, and by the dashboard
 * and the MCP tools on their own when a row was missed.
 */
function rejectedChainSource(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/widgets/rejected/array-from-chain.tsx'));
}

function stillValidSource(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/widgets/valid/01-groceries-by-month.tsx'));
}

/** A widget saved under the previous rules, with a stand-in module. */
function widgetUnderOldRules(User $user, string $source): WidgetDefinition
{
    return WidgetDefinition::factory()->for($user)->checkedUnderOldRules($source)->create([
        'compiled_js' => 'function Old(){return null}export{Old as default};',
        'created_at' => now()->subWeek(),
        'updated_at' => now()->subWeek(),
    ]);
}

/**
 * A user with the flag whose default dashboard holds $widget.
 */
function dashboardHolding(WidgetDefinition $widget): User
{
    $user = $widget->user;
    Feature::for($user)->activate(CustomDashboards::class);
    $dashboard = Dashboard::factory()->for($user)->create();
    DashboardWidget::factory()->for($dashboard)->create(['system_widget' => null, 'widget_definition_id' => $widget->id, 'position' => 0, 'width' => '1/2', 'height' => 'm']);
    $user->forceFill(['default_dashboard_id' => $dashboard->id])->save();

    return $user;
}

/** The compiler as if Bun were missing: it answers nothing usable. */
function withoutWidgetCompiler(): void
{
    app()->instance(WidgetCompiler::class, new WidgetCompiler(base_path('tests/Fixtures/widgets/crashing-compiler.ts')));
}

describe('widgets:revalidate', function () {
    it('recompiles a stale widget that still passes, as no new revision nor an edit', function () {
        $widget = widgetUnderOldRules(User::factory()->create(), stillValidSource());
        $updatedAt = $widget->updated_at;

        artisan('widgets:revalidate')
            ->expectsOutputToContain('1 checked, 1 recompiled, 0 neutralised')
            ->assertSuccessful();

        $widget->refresh();

        expect($widget->compiled_js)->not->toBe('function Old(){return null}export{Old as default};')
            ->toContain('as default')
            ->and($widget->rules_version)->toBe(WidgetRules::VERSION)
            ->and($widget->revision)->toBe(1)
            ->and($widget->revisions()->count())->toBe(1)
            ->and($widget->updated_at->equalTo($updatedAt))->toBeTrue()
            ->and($widget->last_error)->toBeNull();
    });

    it('neutralises a stale widget that no longer passes, with the reasons for its agent', function () {
        $widget = widgetUnderOldRules(User::factory()->create(), rejectedChainSource());
        $updatedAt = $widget->updated_at;

        artisan('widgets:revalidate')
            ->expectsOutputToContain('1 checked, 0 recompiled, 1 neutralised')
            ->assertSuccessful();

        $widget->refresh();

        expect($widget->compiled_js)->toBe(WidgetRules::InertModule)
            ->and($widget->rules_version)->toBe(WidgetRules::VERSION)
            ->and($widget->revision)->toBe(1)
            ->and($widget->updated_at->equalTo($updatedAt))->toBeTrue()
            ->and($widget->source)->toBe(rejectedChainSource())
            ->and($widget->last_error['phase'])->toBe('rules')
            ->and($widget->last_error['revision'])->toBe(1)
            ->and(collect($widget->last_error['errors'])->pluck('message')->implode("\n"))
            ->toContain('Widgets cannot change `Array`', 'insertAdjacentHTML');
    });

    it('leaves widgets checked under the current rules alone', function () {
        $widget = WidgetDefinition::factory()->create(['compiled_js' => 'current module']);
        $this->mock(WidgetCompiler::class)->shouldNotReceive('compile');

        artisan('widgets:revalidate')
            ->expectsOutputToContain('0 checked')
            ->assertSuccessful();

        expect($widget->fresh()->compiled_js)->toBe('current module');
    });

    it('checks every widget with --all', function () {
        $widget = WidgetDefinition::factory()->create(['source' => rejectedChainSource(), 'compiled_js' => 'current module']);

        artisan('widgets:revalidate', ['--all' => true])
            ->expectsOutputToContain('1 neutralised')
            ->assertSuccessful();

        expect($widget->fresh()->compiled_js)->toBe(WidgetRules::InertModule);
    });

    it('fails without a compiler and changes nothing it could not judge', function () {
        $widget = widgetUnderOldRules(User::factory()->create(), stillValidSource());
        withoutWidgetCompiler();

        artisan('widgets:revalidate')
            ->expectsOutputToContain('1 not checked (compiler unavailable)')
            ->assertFailed();

        expect($widget->fresh()->compiled_js)->toBe('function Old(){return null}export{Old as default};')
            ->and($widget->fresh()->rules_version)->toBe(WidgetRules::VERSION - 1);
    });

    it('lets the fixed widget run again once it passes', function () {
        $widget = widgetUnderOldRules(User::factory()->create(), rejectedChainSource());
        artisan('widgets:revalidate')->assertSuccessful();

        $fixed = app(WidgetDefinitionService::class)->update($widget->fresh(), $widget->manifest, stillValidSource(), WidgetOrigin::Mcp);

        expect($fixed->compiled_js)->not->toBe(WidgetRules::InertModule)
            ->and($fixed->rules_version)->toBe(WidgetRules::VERSION)
            ->and($fixed->last_error)->toBeNull();
    });
});

describe('served widgets', function () {
    it('neutralises a stale widget on a dashboard load, without the command', function () {
        $widget = widgetUnderOldRules(User::factory()->onboarded()->create(), rejectedChainSource());
        $user = dashboardHolding($widget);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps('dashboard', fn (AssertableInertia $reload) => $reload
                    ->where("userWidgets.{$widget->id}.js", WidgetRules::InertModule)
                    ->where("userWidgets.{$widget->id}.last_error.phase", 'rules')
                ));

        expect($widget->fresh()->compiled_js)->toBe(WidgetRules::InertModule);
    });

    it('serves a stale widget that still passes with its new module', function () {
        $widget = widgetUnderOldRules(User::factory()->onboarded()->create(), stillValidSource());
        $user = dashboardHolding($widget);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps('dashboard', fn (AssertableInertia $reload) => $reload
                    ->where("userWidgets.{$widget->id}.js", fn (string $js): bool => $js !== 'function Old(){return null}export{Old as default};' && str_contains($js, 'as default'))
                    ->where("userWidgets.{$widget->id}.last_error", null)
                ));
    });

    it('serves a stale widget inert while the compiler is unavailable', function () {
        $widget = widgetUnderOldRules(User::factory()->onboarded()->create(), stillValidSource());
        $user = dashboardHolding($widget);
        withoutWidgetCompiler();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps('dashboard', fn (AssertableInertia $reload) => $reload
                    ->where("userWidgets.{$widget->id}.js", WidgetRules::InertModule)
                ));

        expect($widget->fresh()->rules_version)->toBe(WidgetRules::VERSION - 1);
    });

    it('never puts the old module in the MCP preview', function () {
        $user = User::factory()->create();
        Feature::for($user)->activate(CustomDashboards::class);
        $widget = widgetUnderOldRules($user, rejectedChainSource());
        $user->withAccessToken($user->createToken('mcp', ['mcp:read'])->accessToken);

        $response = WhisperMoneyServer::actingAs($user)->tool(GetWidget::class, ['widget_id' => $widget->id]);
        $result = (fn (): array => $this->response->toArray()['result'])->call($response);
        $body = json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);

        expect($result['_meta'][WidgetPreviewPayload::MetaKey]['js'])->toBe(WidgetRules::InertModule)
            ->and($body['widget']['last_error']['phase'])->toBe('rules')
            ->and($body['widget']['last_error']['errors'])->not->toBeEmpty();
    });

    it('re-validates a revision it goes back to', function () {
        $user = User::factory()->create();
        $service = app(WidgetDefinitionService::class);
        $widget = WidgetDefinition::factory()->for($user)->create(['source' => stillValidSource()]);
        $widget->revisions()->where('number', 1)->update(['source' => rejectedChainSource()]);

        expect(fn () => $service->revert($widget, 1, WidgetOrigin::Web))
            ->toThrow(InvalidWidget::class);
    });

    it('reports a widget already checked as current', function () {
        $widget = WidgetDefinition::factory()->create();
        $this->mock(WidgetCompiler::class)->shouldNotReceive('compile');

        expect(app(WidgetDefinitionService::class)->recheck($widget))->toBe(WidgetRecheck::Current);
    });
});
