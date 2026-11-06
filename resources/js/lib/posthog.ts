import type { PendingVisit } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import posthog, { type PostHogConfig } from 'posthog-js';

const isPostHogEnabled = (): boolean => {
    const enabled = import.meta.env.VITE_POSTHOG_ENABLED;
    if (enabled === undefined || enabled === '') {
        return true;
    }
    return enabled === 'true' || enabled === '1';
};

const getPostHogApiKey = (): string | undefined => {
    return import.meta.env.VITE_POSTHOG_API_KEY;
};

const getPostHogHost = (): string => {
    return import.meta.env.VITE_POSTHOG_HOST || 'https://t.whisper.money';
};

const getPostHogUiHost = (): string => {
    return import.meta.env.VITE_POSTHOG_UI_HOST || 'https://eu.posthog.com';
};

export const isPostHogSessionRecordingEnabled = (): boolean => {
    const enabled = import.meta.env.VITE_POSTHOG_SESSION_RECORDING_ENABLED;
    return enabled === 'true' || enabled === '1';
};

/**
 * The switch for recording the onboarding, and nothing but the onboarding.
 *
 * Off unless set. When on, it takes over from
 * {@link isPostHogSessionRecordingEnabled}: recording is confined to
 * {@link isOnboardingPath} with everything masked, even if the app-wide switch
 * is on too — the narrower promise is the one that holds.
 */
export const isPostHogOnboardingRecordingEnabled = (): boolean => {
    const enabled = import.meta.env.VITE_POSTHOG_ONBOARDING_RECORDING_ENABLED;
    return enabled === 'true' || enabled === '1';
};

/** The onboarding hub (`/onboarding`) and the pages under it (`/onboarding/reveal`, …). */
export const isOnboardingPath = (pathname: string): boolean =>
    pathname === '/onboarding' || pathname.startsWith('/onboarding/');

/**
 * The attributes a replay needs to draw the page, and nothing else: styling
 * (`class`, `style`, the stylesheets rrweb inlines as `_cssText`), the state
 * Tailwind variants key on (`data-state`, `aria-expanded`), SVG geometry so
 * icons render, and rrweb's own layout metadata (`rr_width`, …).
 *
 * An allow-list on purpose: an attribute nobody thought of (an `aria-label`
 * reading out a balance, a `title` with an account name, an `href` with a
 * query string, a `data-*` holding a payee) is masked rather than recorded.
 */
const LAYOUT_ATTRIBUTES = new Set([
    '_cssText',
    'class',
    'style',
    'rel',
    'media',
    'type',
    'role',
    'disabled',
    'hidden',
    'aria-hidden',
    'aria-expanded',
    'aria-selected',
    'aria-checked',
    'aria-disabled',
    'aria-current',
    'aria-orientation',
    'data-state',
    'data-slot',
    'data-side',
    'data-align',
    'data-orientation',
    'data-disabled',
    'data-highlighted',
    'data-selected',
    'data-placeholder',
    'data-variant',
    'data-size',
    'viewBox',
    'xmlns',
    'fill',
    'fill-rule',
    'clip-rule',
    'stroke',
    'stroke-width',
    'stroke-linecap',
    'stroke-linejoin',
    'opacity',
    'transform',
    'width',
    'height',
    'd',
    'points',
    'cx',
    'cy',
    'r',
    'rx',
    'ry',
    'x',
    'y',
    'x1',
    'y1',
    'x2',
    'y2',
]);

/**
 * A fixed `***` rather than one asterisk per character, so a masked amount does
 * not still give away how many digits it has. Whitespace-only text is left
 * alone: it carries nothing and holds the layout together.
 */
const maskWith = (text: string): string => (text.trim() === '' ? text : '***');

/**
 * Keeps an attribute only when it draws the page: see {@link LAYOUT_ATTRIBUTES}.
 * An `<img src>` stays when the image is served over the network (bank logos),
 * never for an inlined `data:` or `blob:` image, which carries its pixels with
 * it. rrweb's own `rr_*` metadata is layout, except `rr_dataURL`, a snapshot of
 * an image.
 */
export function maskOnboardingAttribute(
    name: string,
    value: string,
    element?: Element,
): string {
    const isLayoutMetadata = name.startsWith('rr_') && name !== 'rr_dataURL';
    const isRemoteImage =
        name === 'src' &&
        element?.tagName === 'IMG' &&
        !/^(data|blob):/i.test(value);

    if (LAYOUT_ATTRIBUTES.has(name) || isLayoutMetadata || isRemoteImage) {
        return value;
    }

    return maskWith(value);
}

/**
 * Replay of the onboarding with nothing readable in it: every text node, every
 * input and every attribute that is not layout is replaced by asterisks, and
 * nothing the page sends or logs is captured. What is left is layout, clicks,
 * scrolls and images (bank logos) — enough to see where someone hesitates, not
 * what they have.
 *
 * Set on the client so it wins over whatever the PostHog project's remote
 * config asks for (console logs, network payloads, canvas).
 */
export const ONBOARDING_RECORDING_CONFIG = {
    enable_recording_console_log: false,
    capture_performance: { network_timing: false },
    session_recording: {
        maskAllInputs: true,
        maskTextSelector: '*',
        maskTextFn: maskWith,
        maskInputFn: maskWith,
        maskAttributeFn: maskOnboardingAttribute,
        recordHeaders: false,
        recordBody: false,
        maskCapturedNetworkRequestFn: () => null,
        captureCanvas: { recordCanvas: false },
        recordCrossOriginIframes: false,
        captureJsonLd: false,
    },
} satisfies Partial<PostHogConfig>;

/**
 * The replay settings `posthog.init` boots with. With the onboarding switch on,
 * recording starts only when the page loads on the onboarding; from there
 * {@link installOnboardingSessionRecording} follows the visitor around.
 */
function sessionRecordingConfig(): Partial<PostHogConfig> {
    if (!isPostHogOnboardingRecordingEnabled()) {
        return {
            disable_session_recording: !isPostHogSessionRecordingEnabled(),
        };
    }

    return {
        ...ONBOARDING_RECORDING_CONFIG,
        disable_session_recording: !isOnboardingPath(window.location.pathname),
    };
}

/**
 * Every call into the PostHog SDK, wrapped so it cannot take the page with it.
 *
 * The SDK reaches for browser APIs that are not always there to be reached.
 * A hardened Firefox profile makes `crypto.getRandomValues` throw
 * `OperationError`, which kills `posthog.init` mid-flight (PHP-LARAVEL-5J); a
 * browser blocking site data throws `SecurityError` on the *read* of
 * `window.localStorage`, which kills `posthog.reset` (PHP-LARAVEL-5V). Neither
 * lands somewhere harmless: `initializePostHog` runs at module scope in
 * app.tsx, so a throw there happens before React mounts and white-screens the
 * app, and the other three run inside handlers that were doing real work — the
 * upgrade dialog captures an event on its way to checkout.
 *
 * The failure is swallowed rather than reported. These are browser-environment
 * conditions we cannot act on, and they have already cost us two Sentry issues
 * of pure noise; analytics is never worth a broken page. Guarding here is the
 * only lever we have, since the storage and crypto access that throws is the
 * SDK's own, inside the bundle.
 *
 * The try/catch is doing the real work and there is nothing to "simplify" away:
 * the SDK throws synchronously from inside these calls, so no guard around them
 * can see it coming. Same spirit as ./safe-storage.ts and ./media-query.ts,
 * which exist for the same class of crash.
 */
function withPostHog(call: () => void): void {
    if (typeof window === 'undefined' || !isPostHogEnabled()) {
        return;
    }

    try {
        call();
    } catch (error) {
        // A browser that will not let the SDK run is a browser we collect no
        // analytics from. The page carries on.
        //
        // Loud in development only: the two conditions above are nothing a
        // developer can fix, but a genuinely broken integration — a bad key, a
        // blocked host, a misused SDK method — lands in the same catch and
        // would otherwise be invisible while working on it.
        if (import.meta.env.DEV) {
            console.warn('[PostHog] Call failed and was ignored.', error);
        }
    }
}

export function initializePostHog(): void {
    withPostHog(() => {
        const apiKey = getPostHogApiKey();
        if (!apiKey) {
            console.warn(
                '[PostHog] API key not provided. PostHog will not be initialized.',
            );
            return;
        }

        posthog.init(apiKey, {
            api_host: getPostHogHost(),
            ui_host: getPostHogUiHost(),
            person_profiles: 'always',
            ...sessionRecordingConfig(),
            loaded: () => {
                if (import.meta.env.DEV) {
                    console.log('[PostHog] Initialized successfully');
                }
            },
        });
    });
}

export function identifyUser(
    userId: string,
    properties?: Record<string, unknown>,
): void {
    withPostHog(() => posthog.identify(userId, properties));
}

export function resetPostHog(): void {
    withPostHog(() => posthog.reset());
}

export function captureEvent(
    eventName: string,
    properties?: Record<string, unknown>,
): void {
    withPostHog(() => posthog.capture(eventName, properties));
}

/**
 * Tags everything this browser sends from now on, and the person behind it,
 * with the given properties — an experiment arm, so that events captured long
 * after the screen that assigned it (a bank connection after checkout, the
 * trial converting) can still be split by it.
 */
export function registerProperties(properties: Record<string, unknown>): void {
    withPostHog(() => {
        posthog.register(properties);
        posthog.setPersonProperties(properties);
    });
}

/** Stops tagging this browser's events with a property {@link registerProperties} set. */
export function unregisterProperty(name: string): void {
    withPostHog(() => posthog.unregister(name));
}

/** What the replay was last told, so a poll every few seconds is not a call into the SDK. */
let isRecordingOnboarding: boolean | null = null;

function setOnboardingRecording(shouldRecord: boolean): void {
    if (shouldRecord === isRecordingOnboarding) {
        return;
    }

    isRecordingOnboarding = shouldRecord;

    withPostHog(() => {
        if (shouldRecord) {
            posthog.startSessionRecording();
        } else {
            posthog.stopSessionRecording();
        }
    });
}

/**
 * A visit the visitor asked for: neither a hover prefetch nor a poll. Inertia
 * marks its own poll on the internal shape of the visit, hence the cast (same
 * as ./unattended-requests.ts).
 */
function isAttendedVisit(visit: PendingVisit): boolean {
    const isPoll = (visit as { poll?: boolean }).poll === true;

    return !visit.prefetch && !isPoll;
}

/**
 * Starts the replay when the visitor reaches the onboarding and stops it before
 * they see anything else, across Inertia navigations. A full page load needs
 * nothing from here: {@link initializePostHog} decides from the URL it boots on.
 *
 * Stopping has to happen before the next page is in the DOM, which `navigate`
 * is too late for. So it stops on `before` for any visit that could leave: a
 * GET to another page, or any form post, since `POST /onboarding/complete`
 * lands on the dashboard while a post to `/accounts` comes back. Back/forward
 * has no `before`; the browser's `popstate` comes first instead. It starts only
 * on `navigate`, once the onboarding is what is on screen, and on `finish` when
 * a visit came back to the onboarding without one (a failed post, a cancelled
 * visit) — but not when a newer visit interrupted it, which is the one that
 * decides.
 */
export function installOnboardingSessionRecording(): void {
    if (
        typeof window === 'undefined' ||
        !isPostHogEnabled() ||
        !getPostHogApiKey() ||
        !isPostHogOnboardingRecordingEnabled()
    ) {
        return;
    }

    isRecordingOnboarding = isOnboardingPath(window.location.pathname);

    router.on('before', (event) => {
        const { visit } = event.detail;
        const mayLeave =
            visit.method !== 'get' || !isOnboardingPath(visit.url.pathname);

        if (isAttendedVisit(visit) && mayLeave) {
            setOnboardingRecording(false);
        }
    });

    window.addEventListener('popstate', () => {
        if (!isOnboardingPath(window.location.pathname)) {
            setOnboardingRecording(false);
        }
    });

    router.on('navigate', (event) => {
        setOnboardingRecording(
            isOnboardingPath(
                new URL(event.detail.page.url, window.location.origin).pathname,
            ),
        );
    });

    router.on('finish', (event) => {
        const { visit } = event.detail;

        if (isAttendedVisit(visit) && !visit.interrupted) {
            setOnboardingRecording(isOnboardingPath(window.location.pathname));
        }
    });
}
