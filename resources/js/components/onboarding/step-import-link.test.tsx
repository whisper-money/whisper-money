import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { StepImportLinkSent } from './step-import-link';
import { StepImportUpload } from './step-import-upload';

const { post, captureEvent, toastError, isMobile } = vi.hoisted(() => ({
    post: vi.fn(),
    captureEvent: vi.fn(),
    toastError: vi.fn(),
    isMobile: { value: true },
}));

vi.mock('@/lib/posthog', () => ({ captureEvent }));
vi.mock('sonner', () => ({ toast: { error: toastError } }));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => isMobile.value }));

vi.mock('axios', () => ({
    default: {
        post,
        isAxiosError: (error: unknown) =>
            typeof error === 'object' && error !== null && 'response' in error,
    },
}));

const LINK_ROW = /Email me a link to import from my computer/;

function renderUpload() {
    const onSent = vi.fn();

    render(
        <StepImportUpload
            onFileSelect={vi.fn()}
            onSkip={vi.fn()}
            onQuickExpenses={vi.fn()}
            importLink={{ accountId: 'account-1', onSent }}
        />,
    );

    return { onSent };
}

describe('the import link option', () => {
    afterEach(() => {
        post.mockReset();
        captureEvent.mockReset();
        toastError.mockReset();
        isMobile.value = true;
    });

    it('is offered on a phone, beside the quick expenses', () => {
        renderUpload();

        expect(screen.getByText('No file at hand?')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: LINK_ROW }),
        ).toBeInTheDocument();
    });

    it('is not offered on a computer, where the file is right there', () => {
        isMobile.value = false;
        renderUpload();

        expect(screen.getByText('No file at hand?')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: LINK_ROW })).toBeNull();
    });

    it('asks for the link for that account and reports it without PII', async () => {
        post.mockResolvedValue({ status: 202 });
        const { onSent } = renderUpload();

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: LINK_ROW }));
        });

        expect(post).toHaveBeenCalledWith('/onboarding/import-link', {
            account_id: 'account-1',
        });
        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_import_link_sent',
        );
        expect(onSent).toHaveBeenCalled();
    });

    it('explains the limit when too many links were asked for', async () => {
        post.mockRejectedValue({ response: { status: 429 } });
        const { onSent } = renderUpload();

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: LINK_ROW }));
        });

        expect(toastError).toHaveBeenCalledWith(
            'We already sent you a few links. Check your inbox, or try again in an hour.',
        );
        expect(captureEvent).not.toHaveBeenCalled();
        expect(onSent).not.toHaveBeenCalled();
        // Still there to try again.
        expect(
            screen.getByRole('button', { name: LINK_ROW }),
        ).toBeInTheDocument();
    });
});

describe('StepImportLinkSent', () => {
    it('confirms where the link went and lets the onboarding go on', () => {
        const onContinue = vi.fn();
        const onQuickExpenses = vi.fn();

        render(
            <StepImportLinkSent
                email="ana@example.com"
                onContinue={onContinue}
                onQuickExpenses={onQuickExpenses}
            />,
        );

        expect(screen.getByText('Link sent')).toBeInTheDocument();
        expect(screen.getByText(/ana@example\.com/)).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Continue for now' }),
        );
        expect(onContinue).toHaveBeenCalled();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Type my main expenses instead',
            }),
        );
        expect(onQuickExpenses).toHaveBeenCalled();
    });
});
