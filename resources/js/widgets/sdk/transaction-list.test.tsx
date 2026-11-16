import { LIST_LAYOUT_BELOW_PX } from '@/hooks/use-transaction-layout';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { type WidgetEnv, WidgetSdkProvider } from './context';
import { TransactionList } from './transaction-list';
import {
    type RenderWidgetTransactionTable,
    scopedTransactionTable,
    type WidgetTransactionTableProps,
    WidgetTransactionTableProvider,
} from './transaction-table-host';

// The page the widget preview build draws with: if the list works on it, it
// works in the chat client's iframe, which runs no Inertia app.
vi.mock('@inertiajs/react', async () => {
    const preview = await import('../preview/inertia-page');
    preview.setPreviewPage({ locale: 'en-US', currency: 'EUR' });

    return preview;
});

// jsdom lays nothing out: the real virtualizer would draw no table row.
vi.mock('@tanstack/react-virtual', async (importOriginal) => ({
    ...(await importOriginal<object>()),
    useVirtualizer: (await import('@/lib/virtualizer.fixture'))
        .useEveryItemVirtualizer,
}));

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

/** A split transaction as the API answers it: one row, its lines nested. */
const SPLIT_ROW = apiRow({
    id: 'tx-split',
    category: null,
    amount: -9000,
    splits: [
        {
            id: 'line-2',
            category: { id: 'cat-other', name: 'Taller' },
            amount: -2000,
            position: 1,
        },
        {
            id: 'line-1',
            category: { id: moto.id, name: moto.name },
            amount: -7000,
            position: 0,
        },
    ],
});

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

const host = {
    accounts: {
        'acc-1': {
            type: 'checking' as const,
            bankName: 'BBVA',
            bankLogo: null,
        },
    },
    categories: [moto],
    labels: [panigale] as Label[],
};

function renderList(children: ReactNode, overrides: Partial<WidgetEnv> = {}) {
    return render(
        <WidgetSdkProvider
            value={{
                data: {},
                env: { ...env, ...overrides },
                ...host,
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

    it('only draws the rows where the host offers no table, as in the MCP preview', () => {
        renderList(<TransactionList transactions={ROWS} />);

        expect(screen.queryAllByRole('button')).toHaveLength(0);
        expect(rows()[0]).not.toHaveClass('cursor-pointer');
    });

    /** The host's table over the data the host fetched, as the dashboard draws it. */
    function renderWithHostTable(
        children: ReactNode,
        render: RenderWidgetTransactionTable,
        data: Record<string, unknown> = { tx: { data: [...ROWS, SPLIT_ROW] } },
    ) {
        return renderList(
            <WidgetTransactionTableProvider
                render={scopedTransactionTable(render, data, host)}
            >
                {children}
            </WidgetTransactionTableProvider>,
        );
    }

    it('hands the host table the rows as held, and how each one reads', () => {
        const render = vi.fn<RenderWidgetTransactionTable>(() => (
            <div data-testid="host-table" />
        ));
        renderWithHostTable(
            <TransactionList
                transactions={ROWS}
                hide={['notes', 'labels', 'account']}
                description={(row: { description: string }) =>
                    row.description.replace('Pago en ', '')
                }
                badge={(row: { amount: number }) =>
                    row.amount < -5000 ? 'Compra' : null
                }
                hiddenLabelId={panigale.id}
                limit={2}
                showDayTotals={false}
            />,
            render,
        );

        expect(screen.getByTestId('host-table')).toBeInTheDocument();
        expect(rows()).toHaveLength(0);

        const props: WidgetTransactionTableProps = render.mock.calls[0][0];
        const ducati = props.transactions[2];

        expect(props.transactions.map((row) => row.id)).toEqual([
            'tx-3',
            'tx-2',
            'tx-1',
        ]);
        // What the table edits is the row as the server holds it ...
        expect(ducati).toMatchObject({
            description: 'Pago en Ducati Store',
            notes: 'Casco nuevo',
            label_ids: [panigale.id],
            account_id: 'acc-1',
            currency_code: 'EUR',
            category_id: moto.id,
        });
        // ... and what it draws is the widget's copy of it.
        expect(props.displayRow(ducati)).toMatchObject({
            description: 'Ducati Store',
            notes: null,
            label_ids: [],
            account: undefined,
        });
        expect(props.badgeOf(ducati)).toBe('Compra');
        expect(props.badgeOf(props.transactions[1])).toBeUndefined();
        expect(props).toMatchObject({
            pageSize: 2,
            hideAccount: true,
            hiddenLabelId: panigale.id,
            showDayTotals: false,
        });
    });

    it('draws every row by default, the account and the day totals', () => {
        const render = vi.fn<RenderWidgetTransactionTable>(() => null);
        renderWithHostTable(<TransactionList transactions={ROWS} />, render);

        expect(render.mock.calls[0][0]).toMatchObject({
            pageSize: null,
            hideAccount: false,
            showDayTotals: true,
        });
    });

    it('only draws the rows when readOnly, even where the host offers its table', () => {
        const render = vi.fn<RenderWidgetTransactionTable>(() => null);
        renderWithHostTable(
            <TransactionList transactions={ROWS} readOnly />,
            render,
        );

        expect(render).not.toHaveBeenCalled();
        expect(rows()).toHaveLength(3);
        expect(screen.queryAllByRole('button')).toHaveLength(0);
        expect(rows()[0]).not.toHaveClass('cursor-pointer');
    });

    it('shows its empty state rather than an empty host table', () => {
        const render = vi.fn<RenderWidgetTransactionTable>(() => null);
        renderWithHostTable(<TransactionList transactions={[]} />, render);

        expect(render).not.toHaveBeenCalled();
        expect(screen.getByText('No transactions')).toBeInTheDocument();
    });

    it('draws a split transaction as one row with its lines, at its own amount', () => {
        renderList(<TransactionList transactions={[SPLIT_ROW]} />);

        expect(rows()).toHaveLength(1);
        expect(rows()[0]).toHaveTextContent('Split · 2');
        expect(rows()[0]).toHaveTextContent(/Moto-€70\.00.*Taller-€20\.00/);
        expect(bands()[0]).toHaveTextContent('-€90.00');
    });

    it('draws what the server could not read as still encrypted', () => {
        renderList(
            <TransactionList
                transactions={[apiRow({ description: '', notes: '' })]}
            />,
        );

        expect(rows()[0]).toHaveTextContent('••••••••••');
        expect(rows()[0]).not.toHaveTextContent('Casco nuevo');
    });

    it('hands the host a split transaction as one row with its lines', () => {
        const render = vi.fn<RenderWidgetTransactionTable>(() => null);
        renderWithHostTable(
            <TransactionList transactions={[SPLIT_ROW]} />,
            render,
        );

        const [held] = render.mock.calls[0][0].transactions;

        expect(render.mock.calls[0][0].transactions).toHaveLength(1);
        expect(held).toMatchObject({ amount: -9000, category_id: null });
        // Taller is not one of the user's categories: the editable table
        // gets no placeholder for it, only the read-only list draws one.
        expect(
            held.splits?.map((line) => [
                line.category_id,
                line.category?.name,
                line.amount,
            ]),
        ).toEqual([
            ['cat-other', undefined, -2000],
            [moto.id, 'Moto', -7000],
        ]);
        expect(render.mock.calls[0][0].categories).toEqual([moto]);
    });

    it("acts on the host's own rows, whatever the widget passed for them", () => {
        const render = vi.fn<RenderWidgetTransactionTable>(() => null);
        renderWithHostTable(
            <TransactionList
                transactions={[
                    // tx-1's id on tx-2's text and amount, and the reverse.
                    { ...ROWS[1], id: 'tx-1' },
                    { ...ROWS[0], id: 'tx-2', amount: -1 },
                    apiRow({ id: 'made-up', description: 'Not yours' }),
                    apiRow({
                        id: 'tx-3',
                        transaction_date: '2026-10-05',
                        category: { id: 'cat-evil', name: 'Evil' },
                    }),
                ]}
            />,
            render,
        );

        const props = render.mock.calls[0][0];
        const byId = new Map(props.transactions.map((row) => [row.id, row]));

        expect([...byId.keys()]).toEqual(['tx-3', 'tx-2', 'tx-1']);
        expect(byId.get('tx-1')).toMatchObject({
            description: 'Pago en Ducati Store',
            amount: -12000,
        });
        expect(byId.get('tx-2')).toMatchObject({
            description: 'Pago en Repsol',
            amount: -3000,
        });
        expect(byId.get('tx-3')?.category_id).toBe(moto.id);
        expect(props.categories.map((category) => category.id)).toEqual([
            moto.id,
        ]);
        expect(props.labels).toEqual([panigale]);
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

describe('<TransactionList> read-only, with room for the table', () => {
    // jsdom measures every element 0px wide; this one has a desktop's room.
    beforeEach(() => {
        vi.spyOn(Element.prototype, 'getBoundingClientRect').mockReturnValue({
            width: LIST_LAYOUT_BELOW_PX,
        } as DOMRect);
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    const tableRows = () => screen.queryAllByTestId('transaction-table-row');
    const headings = () =>
        screen.getAllByRole('columnheader').map((cell) => cell.textContent);
    const layout = () =>
        document.querySelector('[data-slot=widget-transaction-list]');

    it('draws the desktop table from 56rem up, the compact list below', () => {
        const { unmount } = renderList(<TransactionList transactions={ROWS} />);

        expect(layout()).toHaveAttribute('data-layout', 'table');
        expect(rows()).toHaveLength(0);
        expect(tableRows().map((row) => row.textContent)).toEqual([
            expect.stringContaining('Venta casco viejo'),
            expect.stringContaining('Pago en Repsol'),
            expect.stringContaining('Pago en Ducati Store'),
        ]);
        unmount();

        vi.mocked(Element.prototype.getBoundingClientRect).mockReturnValue({
            width: LIST_LAYOUT_BELOW_PX - 1,
        } as DOMRect);
        renderList(<TransactionList transactions={ROWS} />);

        expect(layout()).toHaveAttribute('data-layout', 'list');
        expect(rows()).toHaveLength(3);
        expect(tableRows()).toHaveLength(0);
    });

    it('has the columns of /transactions, with nothing to tick, pick or open', () => {
        renderList(<TransactionList transactions={ROWS} />);

        expect(headings()).toEqual([
            'Category',
            'Account',
            'Description',
            'Amount',
        ]);
        expect(screen.queryAllByRole('checkbox')).toHaveLength(0);
        expect(screen.queryAllByRole('combobox')).toHaveLength(0);
        expect(screen.queryAllByRole('button')).toHaveLength(0);
        expect(tableRows()[2]).toHaveTextContent(
            'MotoNóminaPago en Ducati StorePanigaleCasco nuevo-€120.00',
        );
    });

    it('heads each day with its count and total, unless asked not to', () => {
        const { rerender } = renderList(
            <TransactionList transactions={ROWS} />,
        );

        expect(bands()).toHaveLength(2);
        expect(bands()[1]).toHaveTextContent('2 transactions');
        expect(bands()[1]).toHaveTextContent('-€150.00');

        rerender(<TransactionList transactions={ROWS} showDayTotals={false} />);

        expect(bands()[1]).toHaveTextContent('2 transactions');
        expect(bands()[1]).not.toHaveTextContent('€');
    });

    it('leaves out the parts named in hide, the Account column with it', () => {
        renderList(
            <TransactionList
                transactions={[apiRow()]}
                hide={['notes', 'labels', 'account']}
            />,
        );

        expect(headings()).toEqual(['Category', 'Description', 'Amount']);
        expect(tableRows()[0]).toHaveTextContent(
            /^MotoPago en Ducati Store-€120.00$/,
        );
    });

    it('rewrites descriptions, tags rows and drops the hidden label', () => {
        renderList(
            <TransactionList
                transactions={[apiRow()]}
                description={(row: { description: string }) =>
                    row.description.replace('Pago en ', '')
                }
                badge={() => 'Compra'}
                hiddenLabelId={panigale.id}
            />,
        );

        expect(tableRows()[0]).toHaveTextContent('Ducati Store');
        expect(tableRows()[0]).not.toHaveTextContent('Pago en');
        expect(tableRows()[0]).not.toHaveTextContent('Panigale');
        expect(
            within(tableRows()[0]).getByTestId('transaction-list-row-badge'),
        ).toHaveTextContent('Compra');
    });

    it('draws limit rows, and more on Show more', () => {
        renderList(<TransactionList transactions={ROWS} limit={2} />);

        expect(tableRows()).toHaveLength(2);
        fireEvent.click(screen.getByRole('button', { name: 'Show more' }));
        expect(tableRows()).toHaveLength(3);
    });

    it('draws a split transaction as one row, its lines in the Category column', () => {
        renderList(<TransactionList transactions={[SPLIT_ROW]} />);

        expect(tableRows()).toHaveLength(1);
        expect(tableRows()[0]).toHaveTextContent(
            /Split · 2.*Moto.*-€70\.00.*Taller.*-€20\.00/,
        );
        expect(bands()[0]).toHaveTextContent('-€90.00');
    });

    it('masks amounts and descriptions in privacy mode', () => {
        renderList(<TransactionList transactions={ROWS} />, {
            privacyMode: true,
        });

        for (const element of [...tableRows(), ...bands()]) {
            expect(element).not.toHaveTextContent(/€\d|\d€/);
        }
        expect(tableRows()[0]).not.toHaveTextContent('Venta casco viejo');
    });
});
