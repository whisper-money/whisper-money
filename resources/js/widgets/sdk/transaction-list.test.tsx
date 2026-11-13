import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { type WidgetEnv, WidgetSdkProvider } from './context';
import { TransactionList } from './transaction-list';
import {
    type OpenTransaction,
    WidgetTransactionOpenerProvider,
} from './transaction-opener';

// The page the widget preview build draws with: if the list works on it, it
// works in the chat client's iframe, which runs no Inertia app.
vi.mock('@inertiajs/react', async () => {
    const preview = await import('../preview/inertia-page');
    preview.setPreviewPage({ locale: 'en-US', currency: 'EUR' });

    return preview;
});

const env: WidgetEnv = {
    theme: 'light',
    width: '1/2',
    height: 'm',
    isCompact: false,
    privacyMode: false,
    currency: 'EUR',
    locale: 'en-US',
};

const moto = {
    id: 'cat-moto',
    name: 'Moto',
    icon: 'Car',
    color: 'red',
    type: 'expense',
    cashflow_direction: 'outflow',
    parent_id: null,
} as Category;

const panigale = { id: 'label-panigale', name: 'Panigale', color: 'blue' };

/** A row as `GET /transactions` answers it. */
function apiRow(overrides: Record<string, unknown> = {}) {
    return {
        id: 'tx-1',
        space_id: 'space-1',
        account: { id: 'acc-1', name: 'Nómina' },
        transaction_date: '2026-10-01',
        description: 'Pago en Ducati Store',
        amount: -12000,
        currency: 'EUR',
        category: { id: moto.id, name: moto.name },
        category_source: 'manual',
        labels: [{ id: panigale.id, name: panigale.name }],
        source: 'enablebanking',
        notes: 'Casco nuevo',
        ...overrides,
    };
}

const ROWS = [
    apiRow(),
    apiRow({
        id: 'tx-2',
        description: 'Pago en Repsol',
        amount: -3000,
        notes: null,
        labels: [],
    }),
    apiRow({
        id: 'tx-3',
        transaction_date: '2026-10-05',
        description: 'Venta casco viejo',
        amount: 5000,
        notes: null,
    }),
];

function renderList(children: ReactNode, overrides: Partial<WidgetEnv> = {}) {
    return render(
        <WidgetSdkProvider
            value={{
                data: {},
                env: { ...env, ...overrides },
                accounts: {
                    'acc-1': {
                        type: 'checking',
                        bankName: 'BBVA',
                        bankLogo: null,
                    },
                },
                categories: [moto],
                labels: [panigale] as Label[],
            }}
        >
            {children}
        </WidgetSdkProvider>,
    );
}

const rows = () => screen.queryAllByTestId('transaction-list-row');
const bands = () => screen.queryAllByTestId('transaction-day-header');

describe('<TransactionList>', () => {
    it('draws the rows newest first under a band per day with its total', () => {
        renderList(<TransactionList transactions={ROWS} />);

        expect(bands()).toHaveLength(2);
        expect(bands()[0]).toHaveTextContent('Oct 5');
        expect(bands()[0]).toHaveTextContent('+€50.00');
        expect(bands()[1]).toHaveTextContent('Oct 1');
        expect(bands()[1]).toHaveTextContent('-€150.00');
        expect(rows().map((row) => row.textContent)).toEqual([
            expect.stringContaining('Venta casco viejo'),
            // One day's rows by id, newest first, as the server lists them.
            expect.stringContaining('Pago en Repsol'),
            expect.stringContaining('Pago en Ducati Store'),
        ]);
    });

    it('draws each row like the app does: category, bank and account, labels, notes', () => {
        renderList(<TransactionList transactions={[apiRow()]} />);

        expect(rows()[0]).toHaveTextContent(
            'Pago en Ducati Store-€120.00Moto·BNóminaPanigaleCasco nuevo',
        );
    });

    it('reads the { data, truncated } answer of paginate "all"', () => {
        renderList(
            <TransactionList transactions={{ data: ROWS, truncated: false }} />,
        );

        expect(rows()).toHaveLength(3);
    });

    it('rewrites descriptions and tags rows through its formatters', () => {
        renderList(
            <TransactionList
                transactions={ROWS}
                description={(row: { description: string }) =>
                    row.description.replace('Pago en ', '')
                }
                badge={(row: { amount: number }) =>
                    row.amount < -5000 ? 'Compra' : null
                }
            />,
        );

        expect(screen.getByText('Ducati Store')).toBeInTheDocument();
        expect(screen.queryByText(/Pago en/)).not.toBeInTheDocument();
        expect(
            screen.getAllByTestId('transaction-list-row-badge'),
        ).toHaveLength(1);
        expect(
            within(rows()[2]).getByTestId('transaction-list-row-badge'),
        ).toHaveTextContent('Compra');
    });

    it('ignores what a formatter answers that is not a string', () => {
        renderList(
            <TransactionList
                transactions={[apiRow()]}
                description={() => 42}
                badge={() => ({ text: 'x' })}
            />,
        );

        expect(rows()[0]).toHaveTextContent('Pago en Ducati Store');
        expect(
            screen.queryByTestId('transaction-list-row-badge'),
        ).not.toBeInTheDocument();
    });

    it('leaves out the parts named in hide', () => {
        renderList(
            <TransactionList
                transactions={[apiRow()]}
                hide={['notes', 'labels', 'account']}
            />,
        );

        expect(rows()[0]).toHaveTextContent(
            /^Pago en Ducati Store-€120.00Moto$/,
        );
    });

    it('leaves out the label every row carries', () => {
        renderList(
            <TransactionList
                transactions={[apiRow()]}
                hiddenLabelId={panigale.id}
            />,
        );

        expect(rows()[0]).not.toHaveTextContent('Panigale');
    });

    it('draws limit rows, and as many again each time Show more is pressed', () => {
        const many = Array.from({ length: 5 }, (_, index) =>
            apiRow({ id: `tx-${index}`, description: `Row ${index}` }),
        );

        renderList(<TransactionList transactions={many} limit={2} />);

        expect(rows()).toHaveLength(2);
        fireEvent.click(screen.getByRole('button', { name: 'Show more' }));
        expect(rows()).toHaveLength(4);
        fireEvent.click(screen.getByRole('button', { name: 'Show more' }));
        expect(rows()).toHaveLength(5);
        expect(
            screen.queryByRole('button', { name: 'Show more' }),
        ).not.toBeInTheDocument();
    });

    it('totals each day over all its rows, drawn or not', () => {
        renderList(<TransactionList transactions={ROWS} limit={2} />);

        expect(rows()).toHaveLength(2);
        expect(bands()[1]).toHaveTextContent('-€150.00');
    });

    it('leaves the day totals out when asked', () => {
        renderList(
            <TransactionList transactions={ROWS} showDayTotals={false} />,
        );

        expect(bands()[0]).not.toHaveTextContent('€');
    });

    it('masks every amount and description in privacy mode', () => {
        renderList(<TransactionList transactions={ROWS} />, {
            privacyMode: true,
        });

        for (const element of [...rows(), ...bands()]) {
            expect(element).not.toHaveTextContent(/€\d|\d€/);
        }

        // Descriptions too, as everywhere else in the app.
        expect(rows()[0]).not.toHaveTextContent('Venta casco viejo');
        expect(rows()[0]).toHaveTextContent('+€**.**');
    });

    it('says the name of a category the page does not know', () => {
        renderList(
            <TransactionList
                transactions={[
                    apiRow({ category: { id: 'cat-other', name: 'Taller' } }),
                ]}
            />,
        );

        expect(rows()[0]).toHaveTextContent('Taller');
        expect(rows()[0]).not.toHaveTextContent('Uncategorized');
    });

    it('is read-only where the host opens nothing, as in the MCP preview', () => {
        renderList(<TransactionList transactions={ROWS} />);

        expect(screen.queryAllByRole('button')).toHaveLength(0);
        expect(rows()[0]).not.toHaveClass('cursor-pointer');
    });

    function renderOpenable(children: ReactNode, open: OpenTransaction) {
        return renderList(
            <WidgetTransactionOpenerProvider open={open}>
                {children}
            </WidgetTransactionOpenerProvider>,
        );
    }

    it('opens the tapped row by id through the host', () => {
        const open = vi.fn();
        renderOpenable(
            <TransactionList
                transactions={ROWS}
                description={(row: { description: string }) =>
                    row.description.replace('Pago en ', '')
                }
            />,
            open,
        );

        fireEvent.click(screen.getByText('Repsol'));

        expect(open).toHaveBeenCalledTimes(1);
        expect(open).toHaveBeenCalledWith('tx-2');
        expect(rows()[0]).toHaveClass('cursor-pointer');
    });

    it('opens from the keyboard too: each row has a button', () => {
        const open = vi.fn();
        renderOpenable(<TransactionList transactions={ROWS} />, open);

        const [first] = within(rows()[0]).getAllByRole('button');
        fireEvent.click(first);

        expect(open).toHaveBeenCalledTimes(1);
        expect(open).toHaveBeenCalledWith('tx-3');
    });

    it('opens nothing when readOnly', () => {
        const open = vi.fn();
        renderOpenable(<TransactionList transactions={ROWS} readOnly />, open);

        fireEvent.click(rows()[0]);

        expect(open).not.toHaveBeenCalled();
        expect(screen.queryAllByRole('button')).toHaveLength(0);
        expect(rows()[0]).not.toHaveClass('cursor-pointer');
    });

    it('skips rows without an id, a date, an amount or a currency', () => {
        renderList(
            <TransactionList
                transactions={[
                    apiRow(),
                    { id: 'broken' },
                    null,
                    apiRow({ id: 'tx-9', amount: 'a lot' }),
                ]}
            />,
        );

        expect(rows()).toHaveLength(1);
    });

    it('says when there is nothing to list', () => {
        const { rerender } = renderList(<TransactionList transactions={[]} />);

        expect(screen.getByText('No transactions')).toBeInTheDocument();

        rerender(
            <TransactionList
                transactions={undefined}
                emptyTitle="Sin movimientos de la moto"
                emptyDescription="Etiqueta un gasto con Panigale."
            />,
        );

        expect(
            screen.getByText('Sin movimientos de la moto'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Etiqueta un gasto con Panigale.'),
        ).toBeInTheDocument();
    });
});
