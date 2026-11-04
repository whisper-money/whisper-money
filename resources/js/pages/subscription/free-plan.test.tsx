import { act, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import FreePlanConfirm from './free-plan';

const mocks = vi.hoisted(() => ({
    visit: vi.fn(),
    post: vi.fn(),
    httpPost: vi.fn(),
    captureEvent: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => ({
    usePage: () => ({
        props: {
            banks: ['BBVA'],
            transactionsCount: 12,
            hasAiConsent: false,
            gate: 'hard',
        },
    }),
    useHttp: (await import('@/lib/use-http.fixture')).fakeUseHttp(
        mocks.httpPost,
    ),
    router: { visit: mocks.visit, post: mocks.post },
    Head: () => null,
}));

// The shell is not what is under test, and it reaches for app-wide contexts.
vi.mock('@/layouts/subscription-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('@/lib/posthog', () => ({ captureEvent: mocks.captureEvent }));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));

describe('FreePlanConfirm', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.httpPost.mockResolvedValue(undefined);
    });

    it('asks why once the user confirms, before disconnecting anything', () => {
        render(<FreePlanConfirm />);

        fireEvent.click(screen.getByTestId('confirm-free-plan'));

        expect(screen.getByTestId('free-plan-survey')).toBeInTheDocument();
        expect(mocks.post).not.toHaveBeenCalled();
    });

    // The user already chose to disconnect: skipping the question must not
    // take that back.
    it('still disconnects when the survey is skipped', () => {
        render(<FreePlanConfirm />);

        fireEvent.click(screen.getByTestId('confirm-free-plan'));
        fireEvent.click(screen.getByTestId('free-plan-survey-skip'));

        expect(mocks.httpPost).not.toHaveBeenCalled();
        expect(mocks.post).toHaveBeenCalledWith(
            '/subscribe/free-plan',
            {},
            expect.any(Object),
        );
    });

    it('stores the answer, without a gate, then disconnects', async () => {
        render(<FreePlanConfirm />);

        fireEvent.click(screen.getByTestId('confirm-free-plan'));
        fireEvent.click(
            screen.getByTestId(
                'free-plan-survey-reason-dont_trust_bank_connection',
            ),
        );

        await act(async () => {
            fireEvent.click(screen.getByTestId('free-plan-survey-send'));
        });

        expect(mocks.httpPost).toHaveBeenCalledWith(
            '/subscribe/free-plan/survey',
            { reason: 'dont_trust_bank_connection', details: '' },
        );
        expect(mocks.post).toHaveBeenCalledTimes(1);
    });

    it('does not ask again when a failed disconnect is retried', () => {
        mocks.post.mockImplementation(
            (
                _url: string,
                _data: object,
                options: { onError: () => void; onFinish: () => void },
            ) => {
                options.onError();
                options.onFinish();
            },
        );

        render(<FreePlanConfirm />);

        fireEvent.click(screen.getByTestId('confirm-free-plan'));
        fireEvent.click(screen.getByTestId('free-plan-survey-skip'));
        fireEvent.click(screen.getByTestId('confirm-free-plan'));

        expect(
            screen.queryByTestId('free-plan-survey'),
        ).not.toBeInTheDocument();
        expect(mocks.post).toHaveBeenCalledTimes(2);
    });
});
