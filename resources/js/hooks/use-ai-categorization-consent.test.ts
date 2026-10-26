import { type ServerTransaction } from '@/types/transaction';
import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAiCategorizationConsent } from './use-ai-categorization-consent';

const auth = vi.hoisted(() => ({ hasProPlan: true, isSharedAccount: false }));
const { post, start, toastMock } = vi.hoisted(() => ({
    post: vi.fn(),
    start: vi.fn(),
    toastMock: Object.assign(vi.fn(), {
        success: vi.fn(),
        error: vi.fn(),
        loading: vi.fn(() => 'progress'),
        dismiss: vi.fn(),
    }),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth } }),
}));
vi.mock('axios', () => ({ default: { post, get: vi.fn() } }));
vi.mock('sonner', () => ({ toast: toastMock }));
vi.mock('@/hooks/use-poll-job-status', () => ({
    usePollJobStatus: () => ({ start }),
}));
vi.mock('@/routes/ai/consent', () => ({
    store: { url: () => '/ai/consent' },
    dismiss: { url: () => '/ai/consent/dismiss' },
}));
vi.mock('@/routes/ai/categorization', () => ({
    status: (jobId: string) => ({ url: `/ai/categorization/${jobId}` }),
}));

const ROWS = [
    { id: 'a', category_id: null },
    { id: 'b', category_id: 'food' },
] as unknown as ServerTransaction[];

function renderConsent(
    overrides: Partial<{
        hasAiConsent: boolean;
        aiConsentPromptDismissed: boolean;
    }> = {},
) {
    return renderHook(() =>
        useAiCategorizationConsent({
            rows: ROWS,
            refreshRows: vi.fn(),
            hasAiConsent: false,
            aiConsentPromptDismissed: false,
            ...overrides,
        }),
    );
}

describe('useAiCategorizationConsent', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        auth.hasProPlan = true;
        auth.isSharedAccount = false;
        post.mockResolvedValue({ data: {} });
    });

    it('asks a Pro user who has not answered yet', () => {
        expect(renderConsent().result.current.showBanner).toBe(true);
    });

    it('never asks once answered, without Pro, or on a shared account', () => {
        expect(
            renderConsent({ hasAiConsent: true }).result.current.showBanner,
        ).toBe(false);
        expect(
            renderConsent({ aiConsentPromptDismissed: true }).result.current
                .showBanner,
        ).toBe(false);

        auth.isSharedAccount = true;
        expect(renderConsent().result.current.showBanner).toBe(false);

        auth.isSharedAccount = false;
        auth.hasProPlan = false;
        expect(renderConsent().result.current.showBanner).toBe(false);
    });

    it('hides the banner and records the dismissal', () => {
        const { result } = renderConsent();

        act(() => result.current.dismiss());

        expect(result.current.showBanner).toBe(false);
        expect(post).toHaveBeenCalledWith('/ai/consent/dismiss');
    });

    it('spins the uncategorized rows while the backfill it starts runs', async () => {
        post.mockResolvedValue({
            data: { categorization: { job_id: 'job', total: 1 } },
        });
        const { result } = renderConsent();

        await act(() => result.current.enable());

        expect(post).toHaveBeenCalledWith('/ai/consent');
        expect(result.current.showBanner).toBe(false);
        expect([...result.current.categorizingIds]).toEqual(['a']);
        expect(start).toHaveBeenCalledOnce();
    });
});
