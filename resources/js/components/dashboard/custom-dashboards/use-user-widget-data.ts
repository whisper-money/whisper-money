import { dashboard as dashboardWidgetsData } from '@/actions/App/Http/Controllers/Widgets/WidgetDataController';
import { fetchJson } from '@/lib/fetch-json';
import { type UserWidgetDefinition } from '@/types/dashboard';
import { useEffect, useMemo, useState } from 'react';

/** One call of a manifest, answered as `/api/v1` would answer it. */
export interface WidgetCallAnswer {
    status: number;
    body: unknown;
}

interface DashboardWidgetsDataResponse {
    widgets: Record<
        string,
        { revision: number; data: Record<string, WidgetCallAnswer> }
    >;
}

/** The dashboard's variables a manifest can reference as `{{range.from}}`. */
export interface DashboardRange {
    from: string;
    to: string;
}

export type UserWidgetDataState =
    | { status: 'loading' }
    | { status: 'failed' }
    | {
          status: 'ready';
          widgets: DashboardWidgetsDataResponse['widgets'];
          /** The data of the previous request, while a new one loads. */
          refreshing: boolean;
      };

/** What one widget gets to draw with, once its calls are read. */
export interface ResolvedWidgetData {
    /** The body of each call, by the key the manifest gave it. */
    data: Readonly<Record<string, unknown>>;
    /** The calls the API refused, with the reason it gave. */
    failures: { key: string; status: number; message: string }[];
    /** A call that read every page hit the row cap: the data is partial. */
    truncated: boolean;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

/** Splits a widget's answers into the data it reads and what went wrong. */
export function resolveWidgetData(
    answers: Record<string, WidgetCallAnswer>,
): ResolvedWidgetData {
    const resolved: ResolvedWidgetData = {
        data: {},
        failures: [],
        truncated: false,
    };
    const data: Record<string, unknown> = {};

    for (const [key, { status, body }] of Object.entries(answers)) {
        if (status >= 400) {
            const message =
                isRecord(body) && typeof body.message === 'string'
                    ? body.message
                    : `HTTP ${status}`;
            resolved.failures.push({ key, status, message });
            continue;
        }

        data[key] = body;
        resolved.truncated ||= isRecord(body) && body.truncated === true;
    }

    return { ...resolved, data: Object.freeze(data) };
}

/**
 * The data of every user widget on a dashboard, in one request (the batch
 * route), for the range on screen or, without one, the user's current month.
 * It asks again when a widget's revision changes (going back to an earlier
 * version can read other data) or a widget is added.
 */
export function useUserWidgetData(
    dashboardId: string,
    definitions: UserWidgetDefinition[] | undefined,
    enabled: boolean,
    range?: DashboardRange,
): UserWidgetDataState {
    const key = useMemo(() => {
        if (!enabled || definitions === undefined || definitions.length === 0) {
            return null;
        }

        return JSON.stringify([
            dashboardId,
            definitions.map(({ id, revision }) => `${id}@${revision}`).sort(),
            range ?? null,
        ]);
    }, [dashboardId, definitions, enabled, range]);

    const [state, setState] = useState<{
        key: string;
        value: UserWidgetDataState;
    } | null>(null);

    useEffect(() => {
        if (key === null) {
            return;
        }

        let current = true;
        const url = dashboardWidgetsData.url(dashboardId, {
            query: range
                ? { 'range[from]': range.from, 'range[to]': range.to }
                : undefined,
        });

        fetchJson<DashboardWidgetsDataResponse>(url).then(
            ({ widgets }) => {
                if (current) {
                    setState({
                        key,
                        value: { status: 'ready', widgets, refreshing: false },
                    });
                }
            },
            () => {
                if (current) {
                    setState({ key, value: { status: 'failed' } });
                }
            },
        );

        return () => {
            current = false;
        };
        // The key holds every input the request depends on.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    if (state === null || key === null) {
        return { status: 'loading' };
    }

    if (state.key === key) {
        return state.value;
    }

    return state.value.status === 'ready'
        ? { ...state.value, refreshing: true }
        : { status: 'loading' };
}
