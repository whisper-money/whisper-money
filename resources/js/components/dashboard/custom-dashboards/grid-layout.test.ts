import { SYSTEM_WIDGET_IDS } from '@/components/dashboard/widgets/registry';
import {
    type DashboardWidgetData,
    type UserWidgetDefinition,
} from '@/types/dashboard';
import { describe, expect, it } from 'vitest';
import {
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
    it.each([
        ['1/3', 'm', '@4xl:col-span-4', 'row-span-1'],
        ['1/2', 'm', '@2xl:col-span-6', 'row-span-1'],
        ['1/1', 'l', 'col-span-12', 'row-span-2'],
    ] as const)(
        'spans %s of the 12 columns and %s rows',
        (width, height, column, row) => {
            const classes = widgetSizeClassName({ width, height }).split(' ');

            expect(classes).toContain(column);
            expect(classes).toContain(row);
        },
    );

    it('takes the full width on a phone whatever the width', () => {
        for (const width of ['1/3', '1/2', '1/1'] as const) {
            const classes = widgetSizeClassName({ width, height: 'm' }).split(
                ' ',
            );

            expect(classes[0]).toBe('col-span-12');
        }
    });

    it('turns a third into a half on a tablet', () => {
        expect(
            widgetSizeClassName({ width: '1/3', height: 'm' }).split(' '),
        ).toContain('@2xl:col-span-6');
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
