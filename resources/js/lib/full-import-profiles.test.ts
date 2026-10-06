import {
    applySavedProfile,
    countBlankRows,
    defaultMapping,
    detectSource,
    normalizeRows,
    parseIgnoredFlag,
    readFullImportFile,
    splitCategoryPath,
    toSavedProfile,
    unmappedHeaders,
    withUtf8ByteOrderMark,
} from '@/lib/full-import-profiles';
import { DateFormat } from '@/types/import';
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/** A synthetic Banktrack export: made-up accounts, no real person in it. */
function banktrackFile(): File {
    return new File(
        [
            new Uint8Array(
                readFileSync(
                    'resources/js/lib/__fixtures__/banktrack-sample.csv',
                ),
            ),
        ],
        'banktrack-export.csv',
        { type: 'text/csv' },
    );
}

async function readBanktrack() {
    const parsed = await readFullImportFile(banktrackFile(), 'es-ES');
    const mapping = defaultMapping('banktrack', parsed, 'es-ES');

    return { parsed, mapping };
}

const options = {
    fileName: 'banktrack-export.csv',
    supportedCurrencies: ['EUR', 'USD'],
};

describe('reading a Banktrack export', () => {
    it('keeps accented headers and cells intact', async () => {
        const { parsed } = await readBanktrack();

        expect(parsed.headers).toContain('Categorías');
        expect(parsed.headers).toContain('Fecha de ejecución');
        expect(parsed.rows[2]['Concepto']).toBe('Panadería');
        expect(parsed.file.name).toBe('banktrack-export.csv');
    });

    it('is recognised by its header signature', async () => {
        const { parsed } = await readBanktrack();

        expect(detectSource(parsed.headers)).toBe('banktrack');
        expect(detectSource(['Date', 'Amount', 'Description'])).toBeNull();
    });

    it('maps every Banktrack column by default', async () => {
        const { mapping } = await readBanktrack();

        expect(mapping).toMatchObject({
            date: 'Fecha',
            amount: 'Importe',
            description: 'Concepto',
            account: 'Banco',
            accountDetail: 'Producto - Nombre',
            splitByAccountDetail: false,
            notes: 'Descripción',
            category: 'Categorías',
            categorySeparator: ',',
            currency: 'Moneda',
            balance: 'Balance',
            iban: 'Producto - IBAN',
            externalId: 'ID',
            ignored: 'Ignorada',
            dateFormat: DateFormat.DayMonthYear,
        });
    });

    it('leaves the columns it does not import out', async () => {
        const { parsed, mapping } = await readBanktrack();

        expect(unmappedHeaders(mapping, parsed.headers)).toEqual([
            'Fecha de ejecución',
            'Signo cambiado',
            'Contacto - ID',
            'Contacto - Nombre',
            'Contacto - Nota interna',
            'Contacto - NIF',
            'Producto - Nombre',
            'Archivos',
            'Facturas',
        ]);
    });

    it('skips the blank separator row and counts it', async () => {
        const { parsed, mapping } = await readBanktrack();
        const file = normalizeRows(parsed, mapping, options);

        expect(file.blankRows).toBe(1);
        expect(file.unreadable).toEqual([]);
        expect(file.rows).toHaveLength(12);
    });

    it('reads decimal-comma amounts and balances', async () => {
        const { parsed, mapping } = await readBanktrack();
        const { rows } = normalizeRows(parsed, mapping, options);
        const byId = new Map(rows.map((row) => [row.externalId, row]));

        expect(byId.get('bt-0001')).toMatchObject({
            amount: -14.99,
            balance: 2844.93,
            date: '2026-09-24',
        });
        expect(byId.get('bt-0005')?.amount).toBe(-0.9);
        expect(byId.get('bt-0010')?.amount).toBe(20);
        expect(byId.get('bt-0008')?.amount).toBe(-158.68);
        expect(byId.get('bt-0002')?.balance).toBeNull();
    });

    it('splits the category hierarchy', async () => {
        const { parsed, mapping } = await readBanktrack();
        const { rows } = normalizeRows(parsed, mapping, options);

        expect(
            rows.find((row) => row.externalId === 'bt-0006')?.categoryPath,
        ).toEqual(['Empresa', 'Gastos Empresa']);
        expect(
            rows.find((row) => row.externalId === 'bt-0010')?.categoryPath,
        ).toEqual([]);
    });

    it('reads the ignored flag and drops notes that repeat the description', async () => {
        const { parsed, mapping } = await readBanktrack();
        const { rows } = normalizeRows(parsed, mapping, options);
        const byId = new Map(rows.map((row) => [row.externalId, row]));

        expect(byId.get('bt-0008')?.ignored).toBe(true);
        expect(byId.get('bt-0007')?.ignored).toBe(false);
        expect(byId.get('bt-0001')?.notes).toBeNull();
        expect(byId.get('bt-0002')?.notes).toBe(
            'Compra con tarjeta en Mercadona',
        );
    });

    it('groups accounts by Banco alone, or by Banco and product when asked', async () => {
        const { parsed, mapping } = await readBanktrack();
        const byBank = normalizeRows(parsed, mapping, options).rows;
        const byProduct = normalizeRows(
            parsed,
            { ...mapping, splitByAccountDetail: true },
            options,
        ).rows;

        expect(new Set(byBank.map((row) => row.accountKey)).size).toBe(4);
        expect(new Set(byProduct.map((row) => row.accountKey)).size).toBe(6);
        expect(
            byProduct.find((row) => row.externalId === 'bt-0007'),
        ).toMatchObject({
            accountName: 'BBVA Conjunta · Cuenta Conjunta',
        });
    });
});

describe('splitCategoryPath', () => {
    it('splits on the separator and trims every level', () => {
        expect(splitCategoryPath('Empresa, Gastos Empresa', ',')).toEqual([
            'Empresa',
            'Gastos Empresa',
        ]);
        expect(splitCategoryPath('Casa / Luz', '/')).toEqual(['Casa', 'Luz']);
        expect(splitCategoryPath('Sin separador', '')).toEqual([
            'Sin separador',
        ]);
        expect(splitCategoryPath('  ', ',')).toEqual([]);
    });

    it('keeps anything past the third level in the third', () => {
        expect(splitCategoryPath('A, B, C, D', ',')).toEqual([
            'A',
            'B',
            'C, D',
        ]);
    });
});

describe('parseIgnoredFlag', () => {
    it('reads the usual ways of saying yes', () => {
        for (const value of ['TRUE', 'true', '1', 'Sí', 'x', 'yes']) {
            expect(parseIgnoredFlag(value)).toBe(true);
        }

        for (const value of ['FALSE', '', '0', 'no']) {
            expect(parseIgnoredFlag(value)).toBe(false);
        }
    });
});

describe('countBlankRows', () => {
    it('counts the rows missing between consecutive row numbers', () => {
        expect(countBlankRows([2, 3, 4])).toBe(0);
        expect(countBlankRows([2, 3, 5, 8])).toBe(3);
        expect(countBlankRows([])).toBe(0);
    });
});

describe('withUtf8ByteOrderMark', () => {
    it('leaves ASCII files and other formats alone', async () => {
        const ascii = new File(['a,b\n1,2\n'], 'plain.csv');
        const xlsx = new File([new Uint8Array([0xc3, 0xad])], 'book.xlsx');

        expect(await withUtf8ByteOrderMark(ascii)).toBe(ascii);
        expect(await withUtf8ByteOrderMark(xlsx)).toBe(xlsx);
    });

    it('leaves a file that is not valid UTF-8 alone', async () => {
        const latin1 = new File([new Uint8Array([0x61, 0xed, 0x2c])], 'l.csv');

        expect(await withUtf8ByteOrderMark(latin1)).toBe(latin1);
    });
});

describe('saved profiles', () => {
    it('round-trips a mapping and applies only when every column exists', async () => {
        const { parsed, mapping } = await readBanktrack();
        const changed = {
            ...mapping,
            date: 'Fecha de ejecución',
            splitByAccountDetail: true,
            categorySeparator: '/',
        };
        const profile = toSavedProfile(changed);

        expect(applySavedProfile(mapping, profile, parsed.headers)).toEqual(
            changed,
        );
        expect(applySavedProfile(mapping, profile, ['Fecha'])).toEqual(mapping);
        expect(applySavedProfile(mapping, null, parsed.headers)).toEqual(
            mapping,
        );
    });
});
