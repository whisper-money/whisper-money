import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { HeroVideo } from './hero-video';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));

let onScreen: (isIntersecting: boolean) => void = () => {};

function stubBrowser({ reducedMotion }: { reducedMotion: boolean }) {
    vi.stubGlobal(
        'matchMedia',
        vi.fn((query: string) => ({
            matches: reducedMotion && query.includes('reduced-motion'),
            addEventListener: () => {},
            removeEventListener: () => {},
            addListener: () => {},
            removeListener: () => {},
        })),
    );
    vi.stubGlobal(
        'IntersectionObserver',
        class {
            constructor(callback: IntersectionObserverCallback) {
                onScreen = (isIntersecting) =>
                    callback(
                        [{ isIntersecting } as IntersectionObserverEntry],
                        this as unknown as IntersectionObserver,
                    );
            }
            observe() {}
            disconnect() {}
        },
    );
}

const play = vi.fn(() => Promise.resolve());
const pause = vi.fn();

beforeEach(() => {
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockImplementation(play);
    vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(pause);
    document.documentElement.classList.add('dark');
});

afterEach(() => {
    // Unmount first: dropping the class below would otherwise notify the
    // scheme observer of a still-mounted hero.
    cleanup();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    play.mockClear();
    pause.mockClear();
    document.documentElement.classList.remove('dark');
});

function renderHero() {
    return render(
        <HeroVideo language="es" ctaHref="/register" ctaLabel="Get Started" />,
    );
}

const heroVideo = () =>
    document.querySelector<HTMLVideoElement>('[data-slot="hero-video"] video');
const tourVideo = () =>
    document.querySelector<HTMLVideoElement>(
        '[data-slot="product-tour-video"]',
    );

describe('HeroVideo', () => {
    it('opens the tour from the start, unmuted, and pauses the loop meanwhile', async () => {
        stubBrowser({ reducedMotion: false });
        renderHero();

        await waitFor(() => expect(heroVideo()).not.toBeNull());
        act(() => onScreen(true));
        await waitFor(() => expect(play).toHaveBeenCalled());

        fireEvent.click(
            screen.getByRole('button', { name: 'Play the product tour' }),
        );

        const tour = tourVideo();
        expect(tour).not.toBeNull();
        expect(tour).toHaveAttribute(
            'src',
            '/images/landing_videos/product-es-dark.mp4',
        );
        expect(tour!.controls).toBe(true);
        expect(tour!.autoplay).toBe(true);
        expect(tour!.muted).toBe(false);
        expect(
            screen.getByRole('link', { name: 'Get Started' }),
        ).toHaveAttribute('href', '/register');
        expect(pause).toHaveBeenCalled();

        play.mockClear();
        fireEvent.click(
            screen.getByRole('button', { name: 'Close the video' }),
        );

        await waitFor(() => expect(tourVideo()).toBeNull());
        expect(play).toHaveBeenCalled();
    });

    it('keeps the poster for reduced motion, and still plays the tour on request', async () => {
        stubBrowser({ reducedMotion: true });
        renderHero();

        fireEvent.click(
            screen.getByRole('button', { name: 'Play the product tour' }),
        );

        expect(heroVideo()).toBeNull();
        expect(tourVideo()).not.toBeNull();
    });
});
