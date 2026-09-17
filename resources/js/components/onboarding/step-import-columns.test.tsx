import { type ColumnOption, DateFormat } from '@/types/import';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { StepImportColumns } from './step-import-columns';

/**
 * The date column of a spreadsheet, as the reader hands it over: a serial
 * number, because turning those into dates on the way in would coerce a CSV's
 * text dates too.
 */
const SPREADSHEET_COLUMNS: ColumnOption[] = [
    { value: 'F. VALOR', label: 'F. VALOR', examples: [46243] },
    {
        value: 'DESCRIPCIÓN',
        label: 'DESCRIPCIÓN',
        examples: ['Pago en Glovo GLOVO PRIME'],
    },
    { value: 'IMPORTE (€)', label: 'IMPORTE (€)', examples: [-7.99] },
];

function renderColumns(columnOptions: ColumnOption[], dateColumn: string) {
    render(
        <StepImportColumns
            fileName="movements.xls"
            columnOptions={columnOptions}
            mapping={{
                transaction_date: dateColumn,
                description: 'DESCRIPCIÓN',
                amount: 'IMPORTE (€)',
                currency: null,
                balance: null,
                creditor_name: null,
                debtor_name: null,
            }}
            dateFormat={DateFormat.DayMonthYear}
            locale="en-US"
            onMappingChange={vi.fn()}
            onConfirm={vi.fn()}
            onDifferentFile={vi.fn()}
        />,
    );
}

describe('StepImportColumns', () => {
    /**
     * The screen asks the user to confirm a column by showing them a cell from
     * it. `46243` is not something anyone can confirm, and the preview two
     * screens later shows the same cell as a date.
     */
    it('shows a spreadsheet serial as the date it stands for', () => {
        renderColumns(SPREADSHEET_COLUMNS, 'F. VALOR');

        expect(screen.getByText('Aug 9, 2026')).toBeInTheDocument();
        expect(screen.queryByText('46243')).toBeNull();
    });

    it('leaves a date that was already readable alone', () => {
        renderColumns(
            [
                {
                    value: 'Fecha valor',
                    label: 'Fecha valor',
                    examples: ['2026-03-12'],
                },
                ...SPREADSHEET_COLUMNS.slice(1),
            ],
            'Fecha valor',
        );

        expect(screen.getByText('2026-03-12')).toBeInTheDocument();
    });

    /** Only the date row is read; an amount is a number and stays one. */
    it('leaves the other columns as they came', () => {
        renderColumns(SPREADSHEET_COLUMNS, 'F. VALOR');

        expect(screen.getByText('-7.99')).toBeInTheDocument();
        expect(
            screen.getByText('Pago en Glovo GLOVO PRIME'),
        ).toBeInTheDocument();
    });
});
