import { SYSTEM_WIDGET_IDS } from '@/components/dashboard/widgets/registry';
import { type DashboardWidgetData } from '@/types/dashboard';
import { describe, expect, it } from 'vitest';
import {
    missingSystemWidgets,
    placedSystemWidgets,
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

describe('widgetSizeClassName', () => {
    it.each([
        ['1/3', 'm', '@5xl:col-span-4', 'row-span-1'],
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

describe('placedSystemWidgets', () => {
    it('orders the placements by position', () => {
        const placed = placedSystemWidgets([
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

    it('leaves out user widgets and widgets the registry no longer has', () => {
        const placed = placedSystemWidgets([
            placement({
                id: 'user',
                system_widget: null,
                widget_definition_id: 'def',
            }),
            placement({ id: 'gone', system_widget: 'retired-widget' }),
            placement({ id: 'kept', system_widget: 'top-labels' }),
        ]);

        expect(placed.map(({ id }) => id)).toEqual(['kept']);
    });

    it('falls back to the default size when the widget does not admit the stored one', () => {
        const [cashflow] = placedSystemWidgets([
            placement({ system_widget: 'cashflow', width: '1/3', height: 'l' }),
        ]);

        expect(cashflow.size).toEqual({ width: '1/1', height: 'm' });
    });
});

describe('missingSystemWidgets', () => {
    it('offers the system widgets not on the dashboard', () => {
        const placed = placedSystemWidgets([
            placement({ system_widget: 'cashflow' }),
        ]);

        expect(missingSystemWidgets(placed)).toEqual(
            SYSTEM_WIDGET_IDS.filter((id) => id !== 'cashflow'),
        );
        expect(missingSystemWidgets([])).toEqual([...SYSTEM_WIDGET_IDS]);
    });
});
