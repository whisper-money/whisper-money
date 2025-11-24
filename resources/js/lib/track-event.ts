import { usePage } from '@inertiajs/react';
import type { SharedData } from '@/types';

export interface TrackEventPayload {
    step: string;
    user_id?: string | number;
    [key: string]: unknown;
}

export interface TrackEventOptions {
    method?: 'GET' | 'POST';
}

function isLocalEnvironment(): boolean {
    const hostname = window.location.hostname;
    return hostname === 'localhost' || hostname.endsWith('.test');
}

export async function trackEvent(
    eventUuid: string,
    payload: TrackEventPayload,
    options: TrackEventOptions = {},
): Promise<void> {
    const { method = 'POST' } = options;

    if (isLocalEnvironment()) {
        console.log('[Track Event]', {
            eventUuid,
            method,
            payload,
        });
        return;
    }

    const url = `https://metricswave.com/webhooks/${eventUuid}`;

    if (method === 'GET') {
        const queryParams = new URLSearchParams();
        Object.entries(payload).forEach(([key, value]) => {
            if (value !== undefined && value !== null) {
                queryParams.append(key, String(value));
            }
        });
        await fetch(`${url}?${queryParams.toString()}`);
    } else {
        await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(payload),
        });
    }
}

export function useTrackEvent() {
    const { auth } = usePage<SharedData>().props;

    return (eventUuid: string, payload: Omit<TrackEventPayload, 'user_id'>, options?: TrackEventOptions) => {
        const finalPayload: TrackEventPayload = {
            ...payload,
            user_id: auth?.user?.id,
        };
        return trackEvent(eventUuid, finalPayload, options);
    };
}
