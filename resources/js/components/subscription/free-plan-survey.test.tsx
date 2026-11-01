import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { FreePlanSurvey } from './free-plan-survey';

const mocks = vi.hoisted(() => ({
    isMobile: false,
    post: vi.fn(),
    captureEvent: vi.fn(),
    toastSuccess: vi.fn(),
}));

vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => mocks.isMobile }));
vi.mock('@/lib/posthog', () => ({ captureEvent: mocks.captureEvent }));
vi.mock('sonner', () => ({ toast: { success: mocks.toastSuccess } }));

vi.mock('@inertiajs/react', async () => {
    const { fakeUseHttp } = await import('@/lib/use-http.fixture');

    return { useHttp: fakeUseHttp(mocks.post) };
});

function renderSurvey(onContinue = vi.fn()) {
    render(<FreePlanSurvey open gate="soft" onContinue={onContinue} />);

    return onContinue;
}

describe('FreePlanSurvey', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.isMobile = false;
        mocks.post.mockResolvedValue(undefined);
    });

    it('asks why, with every reason as a choice', () => {
        renderSurvey();

        expect(
            screen.getByText("What's keeping you on free?"),
        ).toBeInTheDocument();
        expect(screen.getAllByRole('radio')).toHaveLength(6);
        expect(screen.getByText('Other reason')).toBeInTheDocument();
    });

    it('keeps send disabled until a reason is picked', () => {
        renderSurvey();

        expect(screen.getByTestId('free-plan-survey-send')).toBeDisabled();

        fireEvent.click(
            screen.getByTestId('free-plan-survey-reason-too_expensive'),
        );

        expect(screen.getByTestId('free-plan-survey-send')).toBeEnabled();
    });

    it('only asks for more words on the other reason', () => {
        renderSurvey();

        fireEvent.click(
            screen.getByTestId('free-plan-survey-reason-still_trying'),
        );
        expect(
            screen.queryByTestId('free-plan-survey-details'),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByTestId('free-plan-survey-reason-other'));
        expect(
            screen.getByPlaceholderText('Tell us a bit more (optional)'),
        ).toBeInTheDocument();
    });

    it('sends the answer, thanks the user and continues', async () => {
        const onContinue = renderSurvey();

        fireEvent.click(screen.getByTestId('free-plan-survey-reason-other'));
        fireEvent.change(screen.getByTestId('free-plan-survey-details'), {
            target: { value: 'I only track cash' },
        });

        await act(async () => {
            fireEvent.click(screen.getByTestId('free-plan-survey-send'));
        });

        expect(mocks.post).toHaveBeenCalledWith('/subscribe/free-plan/survey', {
            reason: 'other',
            details: 'I only track cash',
            gate: 'soft',
        });
        expect(mocks.toastSuccess).toHaveBeenCalledWith(
            'Thanks for telling us',
        );
        expect(onContinue).toHaveBeenCalledTimes(1);
    });

    it('takes one answer only, however often Send is clicked', async () => {
        const onContinue = renderSurvey();

        fireEvent.click(
            screen.getByTestId('free-plan-survey-reason-too_expensive'),
        );

        await act(async () => {
            fireEvent.click(screen.getByTestId('free-plan-survey-send'));
            fireEvent.click(screen.getByTestId('free-plan-survey-send'));
        });

        expect(screen.getByTestId('free-plan-survey-send')).toBeDisabled();
        expect(screen.getByTestId('free-plan-survey-skip')).toBeDisabled();
        expect(mocks.post).toHaveBeenCalledTimes(1);
        expect(onContinue).toHaveBeenCalledTimes(1);
    });

    // A privacy-first app: what the user wrote stays in our own database.
    it('never sends the free text to analytics', async () => {
        renderSurvey();

        fireEvent.click(screen.getByTestId('free-plan-survey-reason-other'));
        fireEvent.change(screen.getByTestId('free-plan-survey-details'), {
            target: { value: 'something personal' },
        });

        await act(async () => {
            fireEvent.click(screen.getByTestId('free-plan-survey-send'));
        });

        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'paywall_free_plan_survey_submitted',
            { reason: 'other', gate: 'soft' },
        );
        expect(JSON.stringify(mocks.captureEvent.mock.calls)).not.toContain(
            'something personal',
        );
    });

    it('continues, without the thanks, when the answer fails to save', async () => {
        mocks.post.mockRejectedValue(new Error('offline'));
        const onContinue = renderSurvey();

        fireEvent.click(
            screen.getByTestId('free-plan-survey-reason-use_too_little'),
        );

        await act(async () => {
            fireEvent.click(screen.getByTestId('free-plan-survey-send'));
        });

        expect(onContinue).toHaveBeenCalledTimes(1);
        expect(mocks.toastSuccess).not.toHaveBeenCalled();
    });

    it('lets the user skip without answering, and without the thanks', () => {
        const onContinue = renderSurvey();

        fireEvent.click(screen.getByText('Not now'));

        expect(onContinue).toHaveBeenCalledTimes(1);
        expect(mocks.post).not.toHaveBeenCalled();
        expect(mocks.toastSuccess).not.toHaveBeenCalled();
        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'paywall_free_plan_survey_skipped',
            { gate: 'soft' },
        );
    });

    it('treats closing the dialog as a skip', () => {
        const onContinue = renderSurvey();

        fireEvent.click(screen.getByRole('button', { name: 'Close' }));

        expect(onContinue).toHaveBeenCalledTimes(1);
        expect(mocks.post).not.toHaveBeenCalled();
    });

    it('offers a skip in the bottom sheet on the phone', () => {
        mocks.isMobile = true;
        const onContinue = renderSurvey();

        fireEvent.click(screen.getByTestId('free-plan-survey-skip'));

        expect(screen.getByTestId('free-plan-survey-skip')).toHaveTextContent(
            'Skip',
        );
        expect(onContinue).toHaveBeenCalledTimes(1);
    });
});
