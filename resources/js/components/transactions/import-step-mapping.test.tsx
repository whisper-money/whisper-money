import { type ColumnMapping, DateFormat, type ParsedRow } from '@/types/import';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ImportStepMapping } from './import-step-mapping';

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en-US',
}));

function renderStep(
    tracksBalance: boolean,
    {
        mapping = {},
        parsedData = [],
        onMappingChange = vi.fn(),
    }: {
        mapping?: Partial<ColumnMapping>;
        parsedData?: ParsedRow[];
        onMappingChange?: (
            field: keyof ColumnMapping,
            value: string | string[],
        ) => void;
    } = {},
) {
    render(
        <ImportStepMapping
            columnOptions={[
                { value: 'Saldo', label: 'Saldo', examples: [] },
                { value: 'Tipo', label: 'Tipo', examples: [] },
            ]}
            columnMapping={{
                transaction_date: null,
                description: null,
                amount: null,
                currency: null,
                balance: null,
                creditor_name: null,
                debtor_name: null,
                ...mapping,
            }}
            dateFormat={DateFormat.YearMonthDay}
            dateFormatDetected
            parsedData={parsedData}
            currencyCode="EUR"
            tracksBalance={tracksBalance}
            onMappingChange={onMappingChange}
            onDateFormatChange={vi.fn()}
            onNext={vi.fn()}
            onBack={vi.fn()}
        />,
    );
}

describe('ImportStepMapping', () => {
    it('offers the balance column for an account with a balance', () => {
        renderStep(true);

        expect(document.getElementById('balance-column')).not.toBeNull();
    });

    it('hides every balance control for a credit card', () => {
        renderStep(false);

        expect(document.getElementById('balance-column')).toBeNull();
        expect(document.getElementById('creditor-column')).not.toBeNull();
        expect(document.getElementById('type-column')).not.toBeNull();
    });

    it('lists the type column values with their row counts, ticked as mapped', () => {
        renderStep(true, {
            mapping: { transaction_type: 'Tipo', expense_types: ['gasto'] },
            parsedData: [
                { Tipo: 'Gasto' },
                { Tipo: 'Gasto' },
                { Tipo: 'Ingreso' },
            ],
        });

        expect(screen.getByText('Gasto')).not.toBeNull();
        expect(screen.getByText('· 2 rows')).not.toBeNull();
        expect(screen.getByText('· 1 rows')).not.toBeNull();
        expect(
            document
                .getElementById('expense-type-0')
                ?.getAttribute('aria-checked'),
        ).toBe('true');
        expect(
            document
                .getElementById('expense-type-1')
                ?.getAttribute('aria-checked'),
        ).toBe('false');
    });

    it('reports the expense values as they are ticked and unticked', () => {
        const onMappingChange = vi.fn();
        renderStep(true, {
            mapping: { transaction_type: 'Tipo', expense_types: ['Gasto'] },
            parsedData: [{ Tipo: 'Gasto' }, { Tipo: 'Ingreso' }],
            onMappingChange,
        });

        fireEvent.click(document.getElementById('expense-type-1')!);
        expect(onMappingChange).toHaveBeenLastCalledWith('expense_types', [
            'Gasto',
            'Ingreso',
        ]);

        fireEvent.click(document.getElementById('expense-type-0')!);
        expect(onMappingChange).toHaveBeenLastCalledWith('expense_types', []);
    });

    it('shows no values to tick until a type column is chosen', () => {
        renderStep(true, { parsedData: [{ Tipo: 'Gasto' }] });

        expect(document.getElementById('type-column')).not.toBeNull();
        expect(document.getElementById('expense-type-0')).toBeNull();
    });

    it('keeps a ticked value in sight past the cap on listed values', () => {
        const rare = 'Rare expense';
        const parsedData: ParsedRow[] = [
            ...Array.from({ length: 25 }, (_, index) => [
                { Tipo: `Common ${index}` },
                { Tipo: `Common ${index}` },
            ]).flat(),
            { Tipo: rare },
        ];
        renderStep(true, {
            mapping: { transaction_type: 'Tipo', expense_types: [rare] },
            parsedData,
        });

        expect(screen.getByText(rare)).not.toBeNull();
        expect(screen.queryByText('Common 24')).toBeNull();
        expect(screen.getByText('5 more values not shown')).not.toBeNull();
    });

    it('previews the expense rows with the sign the type column gives them', () => {
        renderStep(true, {
            mapping: {
                transaction_date: 'Fecha',
                description: 'Concepto',
                amount: 'Importe',
                transaction_type: 'Tipo',
                expense_types: ['Gasto'],
            },
            parsedData: [
                {
                    Fecha: '2026-03-01',
                    Concepto: 'Coffee',
                    Importe: '4.50',
                    Tipo: 'Gasto',
                },
                {
                    Fecha: '2026-03-02',
                    Concepto: 'Salary',
                    Importe: '2500',
                    Tipo: 'Ingreso',
                },
            ],
        });

        expect(screen.getByText('-€4.50')).not.toBeNull();
        expect(screen.getByText('€2,500.00')).not.toBeNull();
    });

    it('keeps the column names of the file in the open picker out of autocapture', () => {
        renderStep(true);

        fireEvent.keyDown(document.getElementById('date-column')!, {
            key: 'ArrowDown',
        });

        const content = screen
            .getByRole('option', { name: 'Saldo' })
            .closest('[data-slot="select-content"]');

        expect(content).toHaveClass('ph-no-capture');
    });
});
