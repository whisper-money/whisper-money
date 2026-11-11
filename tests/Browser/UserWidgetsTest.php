<?php

declare(strict_types=1);

use App\Features\CustomDashboards;
use App\Models\User;
use App\Services\Widgets\WidgetCompiler;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * The runtime of user widgets: the compiled fixtures in
 * tests/Fixtures/widgets/valid drawn on the dashboard grid by <UserWidget>,
 * with the SDK, the error boundary and safe mode. Widget definitions are not
 * stored yet (a follow-up adds them and the data route), so the test mounts
 * them on a page of its own, resources/js/pages/testing/user-widgets.tsx,
 * with their data already resolved.
 */
beforeEach(function (): void {
    config(['subscriptions.enabled' => false]);
    fakeCurrencyApi();

    Route::middleware(['web', 'auth'])->get('/testing/user-widgets', fn () => Inertia::render('testing/user-widgets', [
        'widgets' => userWidgetFixtures(),
    ]));
});

/**
 * Every valid fixture compiled once per run, plus one that throws while
 * rendering, with the data each one reads.
 *
 * @return list<array{id: string, js: string, data: array<string, mixed>, width: string, height: string}>
 */
function userWidgetFixtures(): array
{
    static $widgets = null;

    if ($widgets !== null) {
        return $widgets;
    }

    $compiler = app(WidgetCompiler::class);
    $sources = collect(glob(base_path('tests/Fixtures/widgets/valid/*.tsx')) ?: [])
        ->sort()
        ->mapWithKeys(fn (string $path): array => [basename($path, '.tsx') => (string) file_get_contents($path)])
        ->put('99-crashes', <<<'TSX'
            import { useWidgetData } from '@whisper-money/widget-sdk';

            export default function Crashes() {
                const { missing } = useWidgetData();

                return <p>{missing.length}</p>;
            }
            TSX);

    $data = [
        'groceries' => ['data' => [
            ['date' => '2026-09-03', 'amount' => -4210],
            ['date' => '2026-10-11', 'amount' => -3890],
        ]],
        'netWorth' => [['value' => 1200000], ['value' => 1250000]],
        'categories' => [['name' => 'Comida', 'total' => 42000], ['name' => 'Casa', 'total' => 81000]],
        'cashflow' => [['month' => '2026-09', 'net' => 12000], ['month' => '2026-10', 'net' => -3000]],
        'savings' => ['saved' => 30000, 'goal' => 50000],
        'accounts' => [['id' => 'a1', 'name' => 'Nómina', 'balance' => 250000, 'currency' => 'EUR']],
        'budgets' => [
            ['name' => 'Ocio', 'spent' => 120, 'limit' => 100],
            ['name' => 'Super', 'spent' => 50, 'limit' => 100],
            ['name' => 'Ropa', 'spent' => 10, 'limit' => 100],
        ],
        'kpis' => ['income' => 300000, 'expenses' => 210000],
        'labels' => [['name' => 'Viajes', 'total' => 900], ['name' => 'Regalos', 'total' => 300]],
    ];
    $widths = ['1/2', '1/3', '1/3', '1/2', '1/1', '1/3', '1/3', '1/3', '1/2', '1/2', '1/2', '1/2'];

    return $widgets = $sources->values()->map(function (string $source, int $index) use ($compiler, $sources, $data, $widths): array {
        $compilation = $compiler->compile($source);

        expect($compilation->ok)->toBeTrue(json_encode($compilation->errors) ?: '');

        return [
            'id' => $sources->keys()[$index],
            'js' => (string) $compilation->js,
            'data' => $data,
            'width' => $widths[$index] ?? '1/2',
            'height' => 'm',
        ];
    })->all();
}

/** What the page drew, read from the DOM. */
function userWidgetsLayout($page): array
{
    return $page->script(<<<'JS'
        (() => {
            const widgets = [...document.querySelectorAll('[data-widget-id]')];
            const main = document.querySelector('[data-slot="sidebar-inset"]');

            return {
                ready: widgets.filter((cell) => cell.querySelector('.wm-widget[data-status="ready"]') && !cell.querySelector('[role="alert"]')).map((cell) => cell.dataset.widgetId),
                failed: widgets.filter((cell) => cell.querySelector('[role="alert"]')).map((cell) => cell.dataset.widgetId),
                collapsed: widgets.filter((cell) => cell.querySelector('.wm-widget').getBoundingClientRect().width < 100).map((cell) => cell.dataset.widgetId),
                overflow: main.scrollWidth - main.clientWidth,
                dark: document.documentElement.classList.contains('dark'),
                cardBrightness: (() => {
                    // Colors compute to oklch(); a canvas reads any of them back as RGB.
                    const context = document.createElement('canvas').getContext('2d');
                    context.fillStyle = getComputedStyle(document.querySelector('.wm-widget')).backgroundColor;
                    context.fillRect(0, 0, 1, 1);
                    const [r, g, b] = context.getImageData(0, 0, 1, 1).data;

                    return (r + g + b) / 3;
                })(),
            };
        })()
    JS);
}

function userWidgetsUser(): User
{
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate(CustomDashboards::class);

    return $user;
}

it('draws every compiled widget in light and dark mode, and isolates the one that throws', function (bool $dark) {
    actingAs(userWidgetsUser());

    $page = visit('/testing/user-widgets');

    if ($dark) {
        $page = $page->inDarkMode();
    }

    $page->assertSee('Supermercado por mes')
        ->assertSee('Exportar flujo')
        ->assertSee('This widget stopped working')
        ->assertSee('Presupuestos');

    $layout = userWidgetsLayout($page);

    expect($layout['ready'])->toHaveCount(11)
        ->and($layout['failed'])->toBe(['99-crashes'])
        ->and($layout['collapsed'])->toBe([])
        ->and($layout['overflow'])->toBeLessThanOrEqual(0)
        ->and($layout['dark'])->toBe($dark)
        ->and($layout['cardBrightness'])->{$dark ? 'toBeLessThan' : 'toBeGreaterThan'}(128);

    // A widget's own state still works next to the one that failed.
    $page->click('Ver todos')->assertSee('Ropa');
})->with(['light' => false, 'dark' => true]);

it('fits a phone: every widget full width', function () {
    actingAs(userWidgetsUser());

    $page = visit('/testing/user-widgets')
        ->on()
        ->iPhoneSE()
        ->navigate('/testing/user-widgets')
        ->assertSee('Supermercado por mes');

    $layout = userWidgetsLayout($page);
    $widths = $page->script(<<<'JS'
        [...document.querySelectorAll('.wm-widget')].map((widget) => Math.round(widget.getBoundingClientRect().width))
    JS);

    expect($layout['ready'])->toHaveCount(11)
        ->and($layout['overflow'])->toBeLessThanOrEqual(0)
        ->and(array_unique($widths))->toHaveCount(1);
});

it('opens in safe mode on ?safe=1, without any user widget, and leaves it', function () {
    actingAs(userWidgetsUser());

    $page = visit('/testing/user-widgets?safe=1')
        ->assertSee('Safe mode: your widgets are not loaded')
        ->assertMissing('.wm-widget')
        ->assertDontSee('Supermercado por mes');

    $page->click('Load widgets')
        ->assertSee('Supermercado por mes')
        ->assertDontSee('Safe mode: your widgets are not loaded');
});

it('opens in safe mode by itself when the last load never finished', function () {
    actingAs(userWidgetsUser());

    // Once drawn, the page clears its marker after a short grace period.
    $page = visit('/testing/user-widgets')->assertSee('Supermercado por mes')->wait(2);

    // What a tab that froze while drawing its widgets leaves behind.
    $page->script("localStorage.setItem('wm:user-widgets-loading', new Date().toISOString())");

    $page->navigate('/testing/user-widgets')
        ->assertSee('Safe mode: your widgets are not loaded')
        ->assertMissing('.wm-widget')
        ->click('Load widgets')
        ->assertSee('Supermercado por mes');
});

it('mounts no user widget with the flag off', function () {
    actingAs(User::factory()->onboarded()->create());

    visit('/testing/user-widgets')
        ->assertMissing('.wm-widget')
        ->assertDontSee('Safe mode: your widgets are not loaded')
        ->assertNoJavascriptErrors();
});
