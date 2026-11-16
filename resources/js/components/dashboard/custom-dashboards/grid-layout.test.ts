import { SYSTEM_WIDGET_IDS } from '@/components/dashboard/widgets/registry';
import {
    WIDGET_HEIGHTS,
    type WidgetSize,
} from '@/components/dashboard/widgets/types';
import {
    type DashboardWidgetData,
    type UserWidgetDefinition,
} from '@/types/dashboard';
import { describe, expect, it } from 'vitest';
import {
    DASHBOARD_GRID_CLASS_NAME,
    missingSystemWidgets,
    missingUserWidgets,
    placedWidgets,
    widgetSizeClassName,
} from './grid-layout';

function placement(
    overrides: Partial<DashboardWidgetData>,
): DashboardWidgetData {
    return {
        id: 'w1',
        system_widget: 'cashflow',
        widget_definition_id: null,
        position: 0,
        width: '1/1',
        height: 'm',
        config: null,
        ...overrides,
    };
}

function definition(
    id: string,
    size: UserWidgetDefinition['manifest']['size'],
): UserWidgetDefinition {
    return {
        id,
        name: id,
        revision: 1,
        js: '',
        manifest: { size },
        last_error: null,
        last_error_at: null,
        revisions: [],
    };
}

describe('widgetSizeClassName', () => {
    function classesOf(size: WidgetSize): string[] {
        return widgetSizeClassName(size).split(' ');
    }

    it.each([
        ['1/3', '@4xl:col-span-4'],
        ['1/2', '@2xl:col-span-6'],
        ['1/1', 'col-span-12'],
    ] as const)('spans %s of the 12 columns', (width, column) => {
        expect(classesOf({ width, height: 'm' })).toContain(column);
    });

    it('takes the full width on a phone whatever the width', () => {
        for (const width of ['1/3', '1/2', '1/1'] as const) {
            expect(classesOf({ width, height: 'm' })[0]).toBe('col-span-12');
        }
    });

    it('turns a third into a half on a tablet', () => {
        expect(classesOf({ width: '1/3', height: 'm' })).toContain(
            '@2xl:col-span-6',
        );
    });

    it.each(WIDGET_HEIGHTS)(
        'never spans rows at %s, so a row is shared at one height',
        (height) => {
            expect(
                classesOf({ width: '1/2', height }).filter((name) =>
                    name.includes('row-span'),
                ),
            ).toEqual([]);
        },
    );

    it.each([
        ['m', 'min-h-64'],
        ['l', 'min-h-[33.5rem]'],
    ] as const)(
        'keeps %s at least %s on every grid, its content contained from @2xl only',
        (height, minimum) => {
            const classes = classesOf({ width: '1/2', height });

            expect(classes).toContain(minimum);
            expect(classes).toContain('@2xl:contain-size');
            expect(classes).not.toContain('contain-size');
        },
    );

    it('sizes an auto widget to its content on every grid, uncontained', () => {
        // Only a loading skeleton, which has no height of its own, gets room.
        expect(classesOf({ width: '1/1', height: 'a' })).toEqual([
            'col-span-12',
            'has-[[aria-busy=true]]:min-h-40',
        ]);
    });

    it('lets every row take the height of its content', () => {
        const classes = DASHBOARD_GRID_CLASS_NAME.split(' ');

        expect(classes).toContain('auto-rows-auto');
        expect(classes.filter((name) => name.includes('16rem'))).toEqual([]);
    });
});

describe('placedWidgets', () => {
    it('orders the placements by position', () => {
        const placed = placedWidgets([
            placement({
                id: 'b',
                system_widget: 'net-worth',
                position: 1,
                height: 'l',
            }),
            placement({ id: 'a', system_widget: 'cashflow', position: 0 }),
        ]);

        expect(placed.map(({ id }) => id)).toEqual(['a', 'b']);
        expect(placed[1].size).toEqual({ width: '1/1', height: 'l' });
    });

    it('leaves out widgets the registry no longer has', () => {
        const placed = placedWidgets([
            placement({ id: 'gone', system_widget: 'retired-widget' }),
            placement({ id: 'kept', system_widget: 'top-labels' }),
        ]);

        expect(placed.map(({ id }) => id)).toEqual(['kept']);
    });

    it('draws user widgets at their saved size until their manifests load', () => {
        const placed = placedWidgets([
            placement({
                id: 'user',
                system_widget: null,
                widget_definition_id: 'def',
                width: '1/3',
            }),
        ]);

        expect(placed).toEqual([
            {
                kind: 'user',
                id: 'user',
                definitionId: 'def',
                size: { width: '1/3', height: 'm' },
            },
        ]);
    });

    it('fits user widgets to their manifest and drops the ones that are gone', () => {
        const placed = placedWidgets(
            [
                placement({
                    id: 'fits',
                    system_widget: null,
                    widget_definition_id: 'a',
                    width: '1/1',
                }),
                placement({
                    id: 'refit',
                    system_widget: null,
                    widget_definition_id: 'b',
                    width: '1/1',
                    height: 'l',
                }),
                placement({
                    id: 'gone',
                    system_widget: null,
                    widget_definition_id: 'deleted',
                }),
            ],
            {
                a: definition('a', {
                    width: '1/2',
                    height: 'm',
                    allowed_widths: ['1/2', '1/1'],
                }),
                b: definition('b', { width: '1/3', height: 'm' }),
            },
        );

        expect(placed.map(({ id, size }) => [id, size])).toEqual([
            ['fits', { width: '1/1', height: 'm' }],
            ['refit', { width: '1/3', height: 'm' }],
        ]);
    });

    it('falls back to the default size when the widget does not admit the stored one', () => {
        const [cashflow] = placedWidgets([
            placement({ system_widget: 'cashflow', width: '1/3', height: 'l' }),
        ]);

        expect(cashflow.size).toEqual({ width: '1/1', height: 'm' });
    });
});

describe('missingSystemWidgets', () => {
    it('offers the system widgets not on the dashboard', () => {
        const placed = placedWidgets([
            placement({ system_widget: 'cashflow' }),
        ]);

        expect(missingSystemWidgets(placed)).toEqual(
            SYSTEM_WIDGET_IDS.filter((id) => id !== 'cashflow'),
        );
        expect(missingSystemWidgets([])).toEqual([...SYSTEM_WIDGET_IDS]);
    });
});

describe('missingUserWidgets', () => {
    it('offers the user widgets not on the dashboard', () => {
        const placed = placedWidgets([
            placement({ system_widget: null, widget_definition_id: 'a' }),
        ]);
        const size = { width: '1/2', height: 'm' } as const;

        expect(
            missingUserWidgets(placed, [
                { id: 'a', name: 'A', size },
                { id: 'b', name: 'B', size },
            ]).map(({ id }) => id),
        ).toEqual(['b']);
    });
});
