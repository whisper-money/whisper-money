import { describe, expect, it } from 'vitest';
import {
    isSizeAllowed,
    isSystemWidgetId,
    resolveLayout,
    SYSTEM_DASHBOARD_LAYOUT,
    SYSTEM_WIDGET_IDS,
    SYSTEM_WIDGETS,
} from './registry';
import { WIDGET_HEIGHTS, WIDGET_WIDTHS } from './types';

describe('system widget registry', () => {
    it('registers every system widget under its own stable id', () => {
        expect(SYSTEM_WIDGET_IDS).toEqual([
            'net-worth',
            'account-balances',
            'monthly-savings',
            'top-categories',
            'top-labels',
            'cashflow',
        ]);

        for (const id of SYSTEM_WIDGET_IDS) {
            expect(SYSTEM_WIDGETS[id].id).toBe(id);
            expect(SYSTEM_WIDGETS[id].component).toBeTypeOf('function');
        }
    });

    it('only uses the plan sizes and admits each default size', () => {
        for (const widget of Object.values(SYSTEM_WIDGETS)) {
            expect(widget.allowedWidths.length).toBeGreaterThan(0);
            expect(widget.allowedHeights.length).toBeGreaterThan(0);
            widget.allowedWidths.forEach((width) =>
                expect(WIDGET_WIDTHS).toContain(width),
            );
            widget.allowedHeights.forEach((height) =>
                expect(WIDGET_HEIGHTS).toContain(height),
            );
            expect(isSizeAllowed(widget, widget.defaultSize)).toBe(true);
        }
    });

    it('lays out the system dashboard in the order it has always had', () => {
        expect(SYSTEM_DASHBOARD_LAYOUT.map((item) => item.widget)).toEqual([
            'net-worth',
            'account-balances',
            'monthly-savings',
            'top-categories',
            'top-labels',
            'cashflow',
        ]);
        SYSTEM_DASHBOARD_LAYOUT.forEach((item) =>
            expect(item.size).toEqual(SYSTEM_WIDGETS[item.widget].defaultSize),
        );
    });

    it('tells system widget ids apart from anything else', () => {
        expect(isSystemWidgetId('cashflow')).toBe(true);
        expect(isSystemWidgetId('unknown-widget')).toBe(false);
        expect(isSystemWidgetId('toString')).toBe(false);
    });
});

describe('resolveLayout', () => {
    it('keeps a valid layout as it is', () => {
        expect(resolveLayout(SYSTEM_DASHBOARD_LAYOUT)).toEqual(
            SYSTEM_DASHBOARD_LAYOUT,
        );
    });

    it('drops widgets that no longer exist and repeated ones', () => {
        const layout = resolveLayout([
            { widget: 'cashflow', size: { width: '1/2', height: 'm' } },
            { widget: 'gone', size: { width: '1/1', height: 'm' } },
            { widget: 'cashflow', size: { width: '1/1', height: 'm' } },
        ]);

        expect(layout).toEqual([
            { widget: 'cashflow', size: { width: '1/2', height: 'm' } },
        ]);
    });

    it('falls back to the default size when the widget does not admit it', () => {
        const layout = resolveLayout([
            { widget: 'cashflow', size: { width: '1/3', height: 'l' } },
        ]);

        expect(layout).toEqual([
            { widget: 'cashflow', size: SYSTEM_WIDGETS.cashflow.defaultSize },
        ]);
    });
});
