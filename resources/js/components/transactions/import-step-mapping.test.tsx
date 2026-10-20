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
            rowNumbers={parsedData.map((_, index) => index + 2)}
            currencyCode="EUR"
            supportedCurrencies={['EUR']}
            tracksBalance={tracksBalance}
            calculateBalances={false}
            referenceBalance={null}
            referenceBalancePrefilled={false}
            calculateBalancesAvailable
            onMappingChange={onMappingChange}
            onDateFormatChange={vi.fn()}
            onCalculateBalancesChange={vi.fn()}
            onReferenceBalanceChange={vi.fn()}
            onLatestDateChange={vi.fn()}
            onNext={vi.fn()}
            onBack={vi.fn()}
        />,
    );
}

function openOptionalFields() {
    fireEvent.click(screen.getByText('Optional fields'));
}

describe('ImportStepMapping', () => {
    it('offers the balance column and the calculate toggle for an account with a balance', () => {
        renderStep(true);

        expect(
            screen.getByText('Currency, balance, creditor, debtor, type'),
        ).not.toBeNull();
        openOptionalFields();
        expect(document.getElementById('balance-column')).not.toBeNull();
        expect(document.getElementById('calculate-balances')).not.toBeNull();
    });

    it('hides every balance control for a credit card', () => {
        renderStep(false);

        expect(
            screen.getByText('Currency, creditor, debtor, type'),
        ).not.toBeNull();
        openOptionalFields();
        expect(document.getElementById('balance-column')).toBeNull();
        expect(document.getElementById('calculate-balances')).toBeNull();
        expect(document.getElementById('creditor-column')).not.toBeNull();
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
        openOptionalFields();

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
        openOptionalFields();

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
        openOptionalFields();

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
        openOptionalFields();

        expect(screen.getByText(rare)).not.toBeNull();
        expect(screen.queryByText('Common 24')).toBeNull();
        expect(screen.getByText('5 more values not shown')).not.toBeNull();
    });
});
