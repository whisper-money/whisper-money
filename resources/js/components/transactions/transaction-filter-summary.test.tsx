import {
    type TransactionFilterSummary as Summary,
    type TransactionFilters,
} from '@/types/transaction';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TransactionFilterSummary } from './transaction-filter-summary';

let isMobile = false;

vi.mock('@/hooks/use-mobile', () => ({
    useIsMobile: () => isMobile,
}));

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en-US',
}));

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({ isPrivacyModeEnabled: false }),
}));

vi.mock('./transaction-analysis-drawer', () => ({
    TransactionAnalysisDrawer: ({
        open,
        filters,
    }: {
        open: boolean;
        filters: TransactionFilters;
    }) =>
        open ? (
            <div data-testid="analysis-drawer">
                {filters.categoryIds.join(',')}
            </div>
        ) : null,
}));

const filters: TransactionFilters = {
    dateFrom: null,
    dateTo: null,
    amountMin: null,
    amountMax: null,
    categoryIds: ['groceries'],
    categoryTypes: [],
    accountIds: [],
    labelIds: [],
    creditorName: '',
    debtorName: '',
    searchText: '',
};

const trend: Summary['trend'] = [
    { month: '2026-05', total: -50000 },
    { month: '2026-06', total: -70000 },
    { month: '2026-07', total: 0 },
    { month: '2026-08', total: -80000 },
    { month: '2026-09', total: null },
    { month: '2026-10', total: -100000 },
];

function summary(overrides: Partial<Summary> = {}): Summary {
    return {
        count: 21,
        total: -69870,
        average: -3327,
        kind: 'expense',
        currency: 'EUR',
        trend,
        ...overrides,
    };
}

function renderSummary(
    value: Summary | undefined,
    filterDescription = ['Groceries', 'Restaurants'],
) {
    return render(
        <TransactionFilterSummary
            summary={value}
            filters={filters}
            filterDescription={filterDescription}
        />,
    );
}

beforeEach(() => {
    isMobile = false;
});

describe('TransactionFilterSummary on desktop', () => {
    it('shows the count, the total and the average in four cells', () => {
        renderSummary(summary());

        const card = screen.getByRole('region', { name: 'Filter summary' });
        expect(within(card).getByText('Transactions')).toBeInTheDocument();
        expect(within(card).getByText('21')).toBeInTheDocument();
        expect(within(card).getByText('Total spending')).toBeInTheDocument();
        expect(within(card).getByText('-€698.70')).toBeInTheDocument();
        expect(
            within(card).getByText('Average per transaction'),
        ).toBeInTheDocument();
        expect(within(card).getByText('-€33.27')).toBeInTheDocument();
    });

    it('opens the analysis drawer with the current filters', () => {
        renderSummary(summary());

        expect(screen.queryByTestId('analysis-drawer')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /View analysis/ }));

        expect(screen.getByTestId('analysis-drawer')).toHaveTextContent(
            'groceries',
        );
    });

    it('draws the six-month trend, the last month darker', () => {
        renderSummary(summary());

        const bars = [
            ...screen.getByTestId('trend-bars').querySelectorAll('span'),
        ];
        expect(bars.map((bar) => bar.style.height)).toEqual([
            '50%',
            '70%',
            '8%',
            '80%',
            '8%',
            '100%',
        ]);
        expect(bars.at(-1)).toHaveClass('bg-foreground');
    });

    it.each([
        ['income', 'Total income'],
        ['mixed', 'Total'],
    ] as const)('names the total of a %s set "%s"', (kind, label) => {
        renderSummary(summary({ kind }));

        expect(screen.getByText(label)).toBeInTheDocument();
    });

    it('shows a dash when an amount had no rate to convert it', () => {
        renderSummary(summary({ total: null, average: null }));

        expect(screen.getAllByText('—')).toHaveLength(2);
    });

    it('shows a skeleton while the summary is on its way', () => {
        renderSummary(undefined);

        expect(
            screen.getByTestId('filter-summary-skeleton'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /View analysis/ }),
        ).not.toBeInTheDocument();
    });
});

describe('TransactionFilterSummary on a phone', () => {
    beforeEach(() => {
        isMobile = true;
    });

    it('captions the count with its kind and the active filters', () => {
        renderSummary(summary());

        expect(
            screen.getByText('21 expenses · Groceries, Restaurants'),
        ).toBeInTheDocument();
        expect(screen.getByText('-€698.70')).toBeInTheDocument();
        expect(screen.getByText('Average')).toBeInTheDocument();
        expect(screen.getByText('-€33.27')).toBeInTheDocument();
    });

    it.each([
        [{ kind: 'income', count: 1 }, '1 income'],
        [{ kind: 'income', count: 3 }, '3 incomes'],
        [{ kind: 'mixed', count: 4 }, '4 transactions'],
        [{ kind: 'expense', count: 1 }, '1 expense'],
    ] as const)('counts %o as "%s"', (overrides, caption) => {
        renderSummary(summary(overrides), []);

        expect(screen.getByText(caption)).toBeInTheDocument();
    });

    it('opens the analysis from a full-width row with the last five months', () => {
        renderSummary(summary());

        const row = screen.getByRole('button', { name: /View analysis/ });
        expect(row).toHaveClass('min-h-11', 'w-full');
        expect(
            within(row).getByTestId('trend-bars').querySelectorAll('span'),
        ).toHaveLength(5);

        fireEvent.click(row);

        expect(screen.getByTestId('analysis-drawer')).toBeInTheDocument();
    });
});
