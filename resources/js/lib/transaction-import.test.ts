import { loadImportConfig } from '@/lib/import-config-storage';
import { DateFormat } from '@/types/import';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    applyStoredImportConfig,
    applyTransactionSuggestion,
    storeBalancesInBulk,
    type ParsedImportFile,
} from './transaction-import';

const axiosPost = vi.hoisted(() => vi.fn());

vi.mock('@/lib/import-config-storage', () => ({
    loadImportConfig: vi.fn(),
}));

vi.mock('axios', () => ({ default: { post: axiosPost } }));

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

    it('keeps a counterparty off the description the user picked by hand', () => {
        const draft = parsedFile();
        draft.mapping = {
            ...draft.mapping,
            description: ['Concepto', 'Beneficiario'],
            debtor_name: 'Ordenante',
        };

        const next = applyTransactionSuggestion(
            draft,
            rows,
            {
                description: 'Concepto',
                creditor_name: 'Beneficiario',
                debtor_name: 'Beneficiario',
            },
            new Set(['description']),
        );

        expect(next.mapping).toMatchObject({
            description: ['Concepto', 'Beneficiario'],
            creditor_name: null,
            debtor_name: 'Ordenante',
        });
    });
});

describe('storeBalancesInBulk', () => {
    beforeEach(() => {
        axiosPost.mockReset();
    });

    it('sends the balances in requests of at most five thousand days', async () => {
        axiosPost.mockImplementation(
            async (_url: string, body: { balances: unknown[] }) => ({
                data: { written: body.balances.length },
            }),
        );

        const balances = Array.from({ length: 5001 }, (_, day) => ({
            balance_date: `2026-01-${String((day % 28) + 1).padStart(2, '0')}`,
            balance: day,
        }));

        const written = await storeBalancesInBulk(ACCOUNT_ID, balances);

        expect(written).toBe(5001);
        expect(axiosPost).toHaveBeenCalledTimes(2);
        expect(axiosPost.mock.calls[0][0]).toBe(
            `/api/accounts/${ACCOUNT_ID}/balances/bulk`,
        );
        expect(axiosPost.mock.calls[0][1].balances).toHaveLength(5000);
        expect(axiosPost.mock.calls[1][1].balances).toHaveLength(1);
    });

    it('reports what each request wrote, so a failed one keeps the earlier ones counted', async () => {
        axiosPost
            .mockResolvedValueOnce({ data: { written: 4990 } })
            .mockRejectedValueOnce(new Error('boom'));
        const onProgress = vi.fn();

        const balances = Array.from({ length: 5001 }, (_, day) => ({
            balance_date: '2026-01-01',
            balance: day,
        }));

        await expect(
            storeBalancesInBulk(ACCOUNT_ID, balances, onProgress),
        ).rejects.toThrow('boom');
        expect(onProgress).toHaveBeenCalledTimes(1);
        expect(onProgress).toHaveBeenCalledWith(4990, 5000);
    });

    it('sends nothing when there are no balances', async () => {
        expect(await storeBalancesInBulk(ACCOUNT_ID, [])).toBe(0);
        expect(axiosPost).not.toHaveBeenCalled();
    });
});
