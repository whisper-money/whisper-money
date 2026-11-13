import { captureEvent } from '@/lib/posthog';

/**
 * What custom dashboards tell PostHog. Everything here is counts, enums and
 * booleans: never a dashboard or widget name, a widget's code or error
 * message, an amount or anything else the user's data holds. What the agent
 * does through MCP never reaches the browser: the server logs the saves and
 * the gateway reads, from both sides, as counts too.
 */

/** One call of a widget's manifest, as the gateway answered it. */
interface CallAnswer {
    status: number;
    body: unknown;
}

/** What made the "Standard plan only" notice show. */
export type UpgradePromptTrigger =
    | 'new_dashboard'
    | 'locked_dashboard'
    | 'ai_dashboard_guide';

export function trackDashboardCreated(): void {
    captureEvent('custom_dashboard_created', { source: 'web' });
}

/** Going back to an earlier revision, the one way the web changes a widget. */
export function trackWidgetReverted(): void {
    captureEvent('user_widget_updated', { source: 'web', action: 'reverted' });
}

export function trackWidgetRuntimeError(phase: 'load' | 'render'): void {
    captureEvent('user_widget_runtime_error', { phase });
}

/**
 * How a dashboard's widget data request went: how many widgets and calls,
 * and how many the API refused, cut short at the row cap, or the gateway's
 * own limiter turned away.
 */
export function widgetDataUsage(
    widgets: Record<string, { data: Record<string, CallAnswer> }>,
): Record<string, number> {
    const calls = Object.values(widgets).flatMap(({ data }) =>
        Object.values(data),
    );
    const truncated = calls.filter(
        ({ status, body }) =>
            status < 400 &&
            typeof body === 'object' &&
            body !== null &&
            (body as { truncated?: unknown }).truncated === true,
    );

    return {
        widgets: Object.keys(widgets).length,
        calls: calls.length,
        failed_calls: calls.filter(({ status }) => status >= 400).length,
        truncated_calls: truncated.length,
        rate_limited_calls: calls.filter(({ status }) => status === 429).length,
    };
}

export function trackWidgetDataLoaded(
    widgets: Record<string, { data: Record<string, CallAnswer> }>,
): void {
    captureEvent('user_widget_data_loaded', widgetDataUsage(widgets));
}

/** A dashboard opened without the plan, drawn as placeholders. */
export function trackLockedDashboardViewed(widgets: number): void {
    captureEvent('custom_dashboard_locked_viewed', { widgets });
}

export function trackUpgradePrompted(trigger: UpgradePromptTrigger): void {
    captureEvent('custom_dashboard_upgrade_prompted', { trigger });
}

export function trackUpgradeClicked(trigger: UpgradePromptTrigger): void {
    captureEvent('custom_dashboard_upgrade_clicked', { trigger });
}

/** The "Create a dashboard with AI" guide opened from the picker. */
export function trackAiGuideOpened(hasPlan: boolean): void {
    captureEvent('custom_dashboard_ai_guide_opened', { has_plan: hasPlan });
}

/** The guide's example prompt copied, to paste into the assistant. */
export function trackAiGuidePromptCopied(): void {
    captureEvent('custom_dashboard_ai_guide_prompt_copied');
}
