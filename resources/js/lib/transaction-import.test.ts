import { loadImportConfig } from '@/lib/import-config-storage';
import { DateFormat } from '@/types/import';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    applyStoredImportConfig,
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
