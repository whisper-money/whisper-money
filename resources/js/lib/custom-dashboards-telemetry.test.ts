import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    trackDashboardCreated,
    trackLockedDashboardViewed,
    trackUpgradeClicked,
    trackUpgradePrompted,
    trackWidgetDataLoaded,
    trackWidgetReverted,
    trackWidgetRuntimeError,
    widgetDataUsage,
} from './custom-dashboards-telemetry';

const mocks = vi.hoisted(() => ({ captureEvent: vi.fn() }));

vi.mock('@/lib/posthog', () => ({ captureEvent: mocks.captureEvent }));

/** Every property value an event sent: only counts, enums and booleans. */
function sentValues(): unknown[] {
    return mocks.captureEvent.mock.calls.flatMap(([, properties]) =>
        Object.values(properties ?? {}),
    );
}

const SECRET = 'Mercadona 42,10 €';

const WIDGETS = {
    'widget-1': {
        revision: 1,
        data: {
            groceries: {
                status: 200,
                body: { data: [{ description: SECRET }], truncated: true },
            },
            balances: { status: 200, body: { data: [{ name: SECRET }] } },
        },
    },
    'widget-2': {
        revision: 3,
        data: {
            spending: {
                status: 429,
                body: { message: 'Too many widget data requests.' },
            },
            label: { status: 422, body: { message: SECRET } },
        },
    },
};

describe('custom dashboards telemetry', () => {
    beforeEach(() => {
        mocks.captureEvent.mockClear();
    });

    it('counts a dashboard data request without any of its data', () => {
        expect(widgetDataUsage(WIDGETS)).toEqual({
            widgets: 2,
            calls: 4,
            failed_calls: 2,
            truncated_calls: 1,
            rate_limited_calls: 1,
        });

        trackWidgetDataLoaded(WIDGETS);

        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'user_widget_data_loaded',
            expect.objectContaining({ calls: 4 }),
        );
        expect(JSON.stringify(mocks.captureEvent.mock.calls)).not.toContain(
            'Mercadona',
        );
    });

    it('names each event with nothing but counts, enums and booleans', () => {
        trackDashboardCreated();
        trackWidgetReverted();
        trackWidgetRuntimeError('render');
        trackLockedDashboardViewed(3);
        trackUpgradePrompted('locked_dashboard');
        trackUpgradeClicked('new_dashboard');

        expect(mocks.captureEvent.mock.calls).toEqual([
            ['custom_dashboard_created', { source: 'web' }],
            ['user_widget_updated', { source: 'web', action: 'reverted' }],
            ['user_widget_runtime_error', { phase: 'render' }],
            ['custom_dashboard_locked_viewed', { widgets: 3 }],
            [
                'custom_dashboard_upgrade_prompted',
                { trigger: 'locked_dashboard' },
            ],
            ['custom_dashboard_upgrade_clicked', { trigger: 'new_dashboard' }],
        ]);
        expect(
            sentValues().every((value) =>
                ['number', 'boolean', 'string'].includes(typeof value),
            ),
        ).toBe(true);
    });
});
