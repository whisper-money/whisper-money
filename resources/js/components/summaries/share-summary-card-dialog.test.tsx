import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ShareSummaryCardDialog } from './share-summary-card-dialog';

/*
 * The summary screen's share dialog.
 *
 * It is the same dialog the progress screen opens for a medal, so what is worth
 * asserting here is what differs: the two shapes it offers rather than the three
 * download links this replaced, and no switch for withholding an amount —
 * a summary card never draws one in the first place.
 */

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en-US' } }),
}));

function open() {
    render(
        <ShareSummaryCardDialog
            summaryId="8f14e45f-ceea-467a-9f8b-1a2b3c4d5e6f"
            period="2026-08"
            card="savings_rate"
            label="Savings rate"
        >
            <button>share</button>
        </ShareSummaryCardDialog>,
    );

    fireEvent.click(screen.getByRole('button', { name: 'share' }));
}

describe('ShareSummaryCardDialog', () => {
    it('offers the two shapes and both skins', () => {
        open();

        expect(screen.getByRole('radio', { name: /4:5/ })).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: /9:16/ })).toBeInTheDocument();
        expect(
            screen.getByRole('radio', { name: 'Light' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Dark' })).toBeInTheDocument();

        // The 16:9 the old download row carried: nothing unfurls it.
        expect(screen.queryByText(/16:9/)).not.toBeInTheDocument();
    });

    it('has nothing to withhold, so it offers no switch', () => {
        open();

        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    });

    it('names the file after the month and the card', () => {
        open();

        const save = screen.getByRole('link', { name: /Save the picture/ });
        expect(save).toHaveAttribute(
            'download',
            'whisper-money-2026-08-savings_rate-feed-light.png',
        );
        expect(save).toHaveAttribute(
            'href',
            '/summaries/8f14e45f-ceea-467a-9f8b-1a2b3c4d5e6f/card/savings_rate/feed/light',
        );
    });
});
