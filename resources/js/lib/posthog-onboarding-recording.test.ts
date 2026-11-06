import posthog from 'posthog-js';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

type RouterHandler = (event: { detail: Record<string, unknown> }) => void;

const routerHandlers = new Map<string, RouterHandler[]>();

/** Window listeners each test's fresh module adds, removed again after it. */
const windowListeners: Array<[string, EventListenerOrEventListenerObject]> = [];

vi.mock('@inertiajs/react', () => ({
    router: {
        on: vi.fn((name: string, handler: RouterHandler) => {
            routerHandlers.set(name, [
                ...(routerHandlers.get(name) ?? []),
                handler,
            ]);
            return () => {};
        }),
    },
}));

vi.mock('posthog-js', () => ({
    default: {
        init: vi.fn(),
        startSessionRecording: vi.fn(),
        stopSessionRecording: vi.fn(),
    },
}));

function fire(name: string, detail: Record<string, unknown>): void {
    routerHandlers.get(name)?.forEach((handler) => handler({ detail }));
}

function visitTo(
    path: string,
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        visit: {
            url: new URL(path, window.location.origin),
            method: 'get',
            prefetch: false,
            ...overrides,
        },
    };
}

/** An Inertia visit that lands: `before`, then the page swap, then `finish`. */
function navigate(path: string): void {
    fire('before', visitTo(path));
    window.history.pushState({}, '', path);
    fire('navigate', { page: { url: path } });
    fire('finish', visitTo(path));
}

/** Boots the app on `path`, the way app.tsx does on a full page load. */
async function bootOn(path: string): Promise<void> {
    window.history.replaceState({}, '', path);
    // Fresh module per test: the recording state it keeps is per page load.
    const module = await import('./posthog');
    module.initializePostHog();
    module.installOnboardingSessionRecording();
}

function initConfig(): Record<string, unknown> {
    return vi.mocked(posthog.init).mock.calls[0][1] as Record<string, unknown>;
}

describe('onboarding session recording', () => {
    beforeEach(() => {
        const addEventListener = window.addEventListener.bind(window);
        vi.spyOn(window, 'addEventListener').mockImplementation(
            (type, listener, options) => {
                windowListeners.push([type, listener]);
                addEventListener(type, listener, options);
            },
        );
        vi.resetModules();
        vi.clearAllMocks();
        routerHandlers.clear();
        vi.stubEnv('VITE_POSTHOG_ENABLED', 'true');
        vi.stubEnv('VITE_POSTHOG_API_KEY', 'phc_test');
        vi.stubEnv('VITE_POSTHOG_ONBOARDING_RECORDING_ENABLED', 'true');
    });

    afterEach(() => {
        vi.unstubAllEnvs();
        vi.restoreAllMocks();
        windowListeners
            .splice(0)
            .forEach(([type, listener]) =>
                window.removeEventListener(type, listener),
            );
    });

    it('records from the first frame when the app boots on the onboarding', async () => {
        await bootOn('/onboarding');

        expect(initConfig().disable_session_recording).toBe(false);
    });

    it.each(['/', '/dashboard', '/transactions', '/settings/profile'])(
        'never records when the app boots on %s',
        async (path) => {
            await bootOn(path);

            expect(initConfig().disable_session_recording).toBe(true);
            expect(posthog.startSessionRecording).not.toHaveBeenCalled();
        },
    );

    it('starts when an Inertia navigation reaches the onboarding', async () => {
        await bootOn('/subscribe');

        navigate('/onboarding');

        expect(posthog.startSessionRecording).toHaveBeenCalledTimes(1);
        expect(posthog.stopSessionRecording).not.toHaveBeenCalled();
    });

    it('stops before the next page is rendered when leaving the onboarding', async () => {
        await bootOn('/onboarding');

        fire('before', visitTo('/dashboard'));

        // Still on the onboarding: the dashboard is not in the DOM yet.
        expect(posthog.stopSessionRecording).toHaveBeenCalledTimes(1);

        window.history.pushState({}, '', '/dashboard');
        fire('navigate', { page: { url: '/dashboard' } });
        fire('finish', visitTo('/dashboard'));

        expect(posthog.stopSessionRecording).toHaveBeenCalledTimes(1);
        expect(posthog.startSessionRecording).not.toHaveBeenCalled();
    });

    it('keeps recording across the onboarding pages', async () => {
        await bootOn('/onboarding');

        navigate('/onboarding/reveal');
        navigate('/onboarding/summary');

        expect(posthog.stopSessionRecording).not.toHaveBeenCalled();
    });

    it.each([
        ['a prefetch', { prefetch: true }],
        ['a poll', { poll: true }],
    ])(
        'keeps recording through %s to another page',
        async (_label, overrides) => {
            await bootOn('/onboarding');

            fire('before', visitTo('/dashboard', overrides));
            fire('finish', visitTo('/dashboard', overrides));

            expect(posthog.stopSessionRecording).not.toHaveBeenCalled();
        },
    );

    it('stops before the dashboard renders when finishing the onboarding', async () => {
        await bootOn('/onboarding');

        // The exit is a post to an onboarding URL that redirects out.
        fire('before', visitTo('/onboarding/complete', { method: 'post' }));

        expect(posthog.stopSessionRecording).toHaveBeenCalledTimes(1);

        window.history.pushState({}, '', '/dashboard');
        fire('navigate', { page: { url: '/dashboard' } });
        fire('finish', visitTo('/onboarding/complete', { method: 'post' }));

        expect(posthog.startSessionRecording).not.toHaveBeenCalled();
    });

    it('picks up again when a form post comes back to the onboarding', async () => {
        await bootOn('/onboarding');

        fire('before', visitTo('/accounts', { method: 'post' }));
        fire('navigate', { page: { url: '/onboarding' } });
        fire('finish', visitTo('/accounts', { method: 'post' }));

        expect(posthog.stopSessionRecording).toHaveBeenCalledTimes(1);
        expect(posthog.startSessionRecording).toHaveBeenCalledTimes(1);
    });

    it('stops on back/forward before the previous page is restored', async () => {
        await bootOn('/onboarding');

        window.history.pushState({}, '', '/subscribe');
        window.dispatchEvent(new PopStateEvent('popstate'));

        expect(posthog.stopSessionRecording).toHaveBeenCalledTimes(1);

        fire('navigate', { page: { url: '/subscribe' } });

        expect(posthog.startSessionRecording).not.toHaveBeenCalled();
    });

    it('starts only once the onboarding is on screen when going back to it', async () => {
        await bootOn('/subscribe');

        window.history.pushState({}, '', '/onboarding');
        window.dispatchEvent(new PopStateEvent('popstate'));

        // The page being left is still in the DOM.
        expect(posthog.startSessionRecording).not.toHaveBeenCalled();

        fire('navigate', { page: { url: '/onboarding' } });

        expect(posthog.startSessionRecording).toHaveBeenCalledTimes(1);
    });

    it('resumes when a visit away is cancelled and the visitor stays', async () => {
        await bootOn('/onboarding');

        fire('before', visitTo('/dashboard'));
        fire('finish', visitTo('/dashboard'));

        expect(posthog.stopSessionRecording).toHaveBeenCalledTimes(1);
        expect(posthog.startSessionRecording).toHaveBeenCalledTimes(1);
    });

    it('never starts anywhere outside the onboarding', async () => {
        await bootOn('/dashboard');

        navigate('/transactions');
        navigate('/settings/profile');
        navigate('/onboarding-tips');
        navigate('/');

        expect(posthog.startSessionRecording).not.toHaveBeenCalled();
    });

    it.each([
        ['aria-label', 'Balance 900'],
        ['title', 'BBVA Nómina'],
        ['alt', 'Víctor Falcón'],
        ['href', '/accounts?name=Ahorro'],
        ['data-payee', 'Mercadona'],
        ['placeholder', 'IBAN ES12'],
        ['id', 'account-ahorro'],
        ['src', 'data:image/png;base64,iVBORw0'],
        ['src', 'blob:https://whisper.money/1'],
        ['rr_dataURL', 'data:image/png;base64,iVBORw0'],
    ])('masks the %s attribute in the replay', async (name, value) => {
        const { maskOnboardingAttribute } = await import('./posthog');

        expect(
            maskOnboardingAttribute(name, value, document.createElement('img')),
        ).toBe('***');
    });

    it('leaves the replay to a newer visit when one interrupts the visit away', async () => {
        await bootOn('/onboarding');

        fire('before', visitTo('/dashboard'));
        fire('before', visitTo('/transactions'));
        fire('finish', visitTo('/dashboard', { interrupted: true }));

        expect(posthog.startSessionRecording).not.toHaveBeenCalled();
    });

    it('does nothing at all with the switch off', async () => {
        vi.stubEnv('VITE_POSTHOG_ONBOARDING_RECORDING_ENABLED', 'false');

        await bootOn('/onboarding');
        navigate('/onboarding/reveal');

        expect(initConfig().disable_session_recording).toBe(true);
        expect(initConfig().session_recording).toBeUndefined();
        expect(routerHandlers.size).toBe(0);
        expect(posthog.startSessionRecording).not.toHaveBeenCalled();
    });

    it('installs nothing with PostHog itself off', async () => {
        vi.stubEnv('VITE_POSTHOG_ENABLED', 'false');

        await bootOn('/onboarding');

        expect(routerHandlers.size).toBe(0);
        expect(posthog.init).not.toHaveBeenCalled();
    });

    it('confines recording to the onboarding even with the app-wide switch on', async () => {
        vi.stubEnv('VITE_POSTHOG_SESSION_RECORDING_ENABLED', 'true');

        await bootOn('/dashboard');

        expect(initConfig().disable_session_recording).toBe(true);
    });

    it('masks every text, input and text-bearing attribute and captures no network or console', async () => {
        await bootOn('/onboarding');

        const config = initConfig();
        const recording = config.session_recording as Record<
            string,
            unknown
        > & {
            maskTextFn: (text: string) => string;
            maskInputFn: (text: string) => string;
            maskAttributeFn: (
                name: string,
                value: string,
                element?: Element,
            ) => string;
            maskCapturedNetworkRequestFn: (request: unknown) => unknown;
        };

        expect(recording).toMatchObject({
            maskAllInputs: true,
            maskTextSelector: '*',
            recordHeaders: false,
            recordBody: false,
            recordCrossOriginIframes: false,
            captureJsonLd: false,
            captureCanvas: { recordCanvas: false },
        });
        expect(config.enable_recording_console_log).toBe(false);
        expect(config.capture_performance).toEqual({ network_timing: false });

        // Fixed length: a masked amount does not give away its digit count.
        expect(recording.maskTextFn('1.234,56 €')).toBe('***');
        expect(recording.maskTextFn('12 €')).toBe('***');
        expect(recording.maskTextFn('\n  ')).toBe('\n  ');
        expect(recording.maskInputFn('Víctor')).toBe('***');
        // Bank logos and styling stay, or the replay shows nothing at all.
        expect(
            recording.maskAttributeFn(
                'src',
                '/logos/bbva.svg',
                document.createElement('img'),
            ),
        ).toBe('/logos/bbva.svg');
        expect(
            recording.maskAttributeFn(
                'src',
                '/media/statement.pdf',
                document.createElement('iframe'),
            ),
        ).toBe('***');
        expect(recording.maskAttributeFn('class', 'flex gap-2')).toBe(
            'flex gap-2',
        );
        expect(recording.maskAttributeFn('_cssText', '.a{color:red}')).toBe(
            '.a{color:red}',
        );
        expect(recording.maskAttributeFn('data-state', 'open')).toBe('open');
        expect(recording.maskAttributeFn('rr_width', '120px')).toBe('120px');
        expect(
            recording.maskCapturedNetworkRequestFn({ name: '/onboarding' }),
        ).toBeNull();
    });
});
