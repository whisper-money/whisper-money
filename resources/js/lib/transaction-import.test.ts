import { loadImportConfig } from '@/lib/import-config-storage';
import { DateFormat } from '@/types/import';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    applyStoredImportConfig,
    applyTransactionSuggestion,
    type ParsedImportFile,
} from './transaction-import';

vi.mock('@/lib/import-config-storage', () => ({
    loadImportConfig: vi.fn(),
}));

const ACCOUNT_ID = '00000000-0000-0000-0000-000000000001';

function parsedFile(): ParsedImportFile {
    return {
        file: new File([''], 'money-pro.csv'),
        rows: [],
        rowNumbers: [],
        headers: ['Fecha', 'Descripción', 'Cantidad', 'Tipo'],
        columnOptions: [],
        mapping: {
            transaction_date: 'Fecha',
            description: 'Descripción',
            amount: 'Cantidad',
            currency: null,
            balance: null,
            creditor_name: null,
            debtor_name: null,
            transaction_type: 'Tipo',
            expense_types: ['Gasto'],
        },
        dateFormat: DateFormat.DayMonthYear,
        dateFormatDetected: true,
        dateFormatAmbiguous: false,
    };
}

describe('applyStoredImportConfig with a type column', () => {
    beforeEach(() => {
        vi.mocked(loadImportConfig).mockReset();
    });

    it('applies the stored expense values, which are not columns of the file', async () => {
        vi.mocked(loadImportConfig).mockResolvedValue({
            columnMapping: {
                ...parsedFile().mapping,
                expense_types: ['Gasto', 'Ajuste de saldo'],
            },
            dateFormat: DateFormat.DayMonthYear,
        });

        const applied = await applyStoredImportConfig(parsedFile(), ACCOUNT_ID);

        expect(applied?.mapping.transaction_type).toBe('Tipo');
        expect(applied?.mapping.expense_types).toEqual([
            'Gasto',
            'Ajuste de saldo',
        ]);
    });

    it('keeps the detected type column under a layout saved before it existed', async () => {
        vi.mocked(loadImportConfig).mockResolvedValue({
            columnMapping: {
                transaction_date: 'Fecha',
                description: 'Descripción',
                amount: 'Cantidad',
                currency: null,
                balance: null,
                creditor_name: null,
                debtor_name: null,
            },
            dateFormat: DateFormat.DayMonthYear,
        });

        const applied = await applyStoredImportConfig(parsedFile(), ACCOUNT_ID);

        expect(applied?.mapping.transaction_type).toBe('Tipo');
        expect(applied?.mapping.expense_types).toEqual(['Gasto']);
    });

    it('ignores a stored layout whose type column this file lacks', async () => {
        vi.mocked(loadImportConfig).mockResolvedValue({
            columnMapping: {
                ...parsedFile().mapping,
                transaction_type: 'Movement type',
            },
            dateFormat: DateFormat.DayMonthYear,
        });

        expect(
            await applyStoredImportConfig(parsedFile(), ACCOUNT_ID),
        ).toBeNull();
    });

    it('applies a stored layout whose type column was set back to none', async () => {
        vi.mocked(loadImportConfig).mockResolvedValue({
            columnMapping: {
                ...parsedFile().mapping,
                creditor_name: '',
                transaction_type: '',
                expense_types: [],
            },
            dateFormat: DateFormat.DayMonthYear,
        });

        const applied = await applyStoredImportConfig(parsedFile(), ACCOUNT_ID);

        expect(applied?.mapping.transaction_type).toBe('');
        expect(applied?.mapping.expense_types).toEqual([]);
    });
});

describe('applyStoredImportConfig with the AI flag', () => {
    it('says the stored layout was the AI suggestion, unchanged', async () => {
        vi.mocked(loadImportConfig).mockResolvedValue({
            columnMapping: parsedFile().mapping,
            dateFormat: DateFormat.DayMonthYear,
            aiSuggested: true,
        });

        expect(
            (await applyStoredImportConfig(parsedFile(), ACCOUNT_ID))
                ?.aiSuggested,
        ).toBe(true);
    });

    it('reads a layout saved before the flag existed as not suggested', async () => {
        vi.mocked(loadImportConfig).mockResolvedValue({
            columnMapping: parsedFile().mapping,
            dateFormat: DateFormat.DayMonthYear,
        });

        expect(
            (await applyStoredImportConfig(parsedFile(), ACCOUNT_ID))
                ?.aiSuggested,
        ).toBe(false);
    });
});

describe('applyTransactionSuggestion', () => {
    const rows = [
        {
            Fecha: '2025-03-01',
            Valor: '15/03/2025',
            Tipo: 'Gasto',
            Clase: 'Af',
        },
        {
            Fecha: '2025-03-02',
            Valor: '16/03/2025',
            Tipo: 'Ingreso',
            Clase: 'Bij',
        },
    ];

    it('reads a newly suggested date column with its own format', () => {
        const next = applyTransactionSuggestion(
            parsedFile(),
            rows,
            { transaction_date: 'Valor' },
            new Set(),
        );

        expect(next.mapping.transaction_date).toBe('Valor');
        expect(next.dateFormat).toBe(DateFormat.DayMonthYear);
        expect(next.dateFormatDetected).toBe(true);
    });

    it('starts the expense values over for a newly suggested type column', () => {
        const next = applyTransactionSuggestion(
            parsedFile(),
            rows,
            { transaction_type: 'Clase' },
            new Set(),
        );

        expect(next.mapping.transaction_type).toBe('Clase');
        expect(next.mapping.expense_types).toEqual(['Af']);
    });

    it('leaves the fields the user picked, and what follows from them, alone', () => {
        const next = applyTransactionSuggestion(
            parsedFile(),
            rows,
            {
                transaction_date: 'Valor',
                transaction_type: 'Clase',
                amount: 'Importe',
            },
            new Set(['transaction_date', 'transaction_type']),
        );

        expect(next.mapping).toMatchObject({
            transaction_date: 'Fecha',
            transaction_type: 'Tipo',
            expense_types: ['Gasto'],
            amount: 'Importe',
        });
        expect(next.dateFormat).toBe(DateFormat.DayMonthYear);
    });
});
