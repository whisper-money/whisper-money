import {
    fileProblem,
    importableReads,
    importFileName,
    mergeImportFiles,
    sameFile,
    sameHeaders,
    type PickedFile,
} from '@/lib/full-import-files';
import {
    defaultMapping,
    detectSource,
    normalizeRows,
} from '@/lib/full-import-profiles';
import { parseImportFile } from '@/lib/transaction-import';
import { describe, expect, it } from 'vitest';

const options = { fileName: 'unused.csv', supportedCurrencies: ['EUR'] };

function csv(name: string, lines: string[]): File {
    return new File([lines.join('\n') + '\n'], name, { type: 'text/csv' });
}

async function read(name: string, lines: string[]) {
    return parseImportFile(csv(name, lines), 'en-US');
}

const HEADER = 'Date,Amount,Description,Account';

describe('reading several files as one export', () => {
    it('joins the rows of files that share their headers', async () => {
        const first = await read('part-1.csv', [
            HEADER,
            '2026-01-02,-5.00,Coffee,Checking',
            '2026-01-03,-7.50,Lunch,Savings',
        ]);
        const second = await read('part-2.csv', [
            HEADER,
            '2026-02-01,1200.00,Salary,Checking',
        ]);

        const merged = mergeImportFiles([first, second], 'en-US');

        expect(merged.headers).toEqual(first.headers);
        expect(merged.rows).toHaveLength(3);
        expect(merged.rows[2]['Description']).toBe('Salary');
        expect(merged.file.name).toBe('part-1.csv');

        const normalized = normalizeRows(
            merged,
            defaultMapping('generic', merged),
            options,
        );

        expect(normalized.rows.map((row) => row.accountName)).toEqual([
            'Checking',
            'Savings',
            'Checking',
        ]);
    });

    it('lines up headers spelled differently under the first file', async () => {
        const first = await read('part-1.csv', [
            HEADER,
            '2026-01-02,-5.00,Coffee,Checking',
        ]);
        const second = await read('part-2.csv', [
            'account,DESCRIPTION,amount,date',
            'Checking,Salary,1200.00,2026-02-01',
        ]);

        expect(sameHeaders(first.headers, second.headers)).toBe(true);

        const merged = mergeImportFiles([first, second]);

        expect(merged.rows[1]).toEqual({
            Date: '2026-02-01',
            Amount: '1200.00',
            Description: 'Salary',
            Account: 'Checking',
        });
    });

    it('keeps each row numbered within its own file', async () => {
        const first = await read('part-1.csv', [
            HEADER,
            '2026-01-02,-5.00,Coffee,Checking',
            'not a date,-1.00,Broken,Checking',
        ]);
        const second = await read('part-2.csv', [
            HEADER,
            '2026-02-01,1200.00,Salary,Checking',
            ',,,',
            'still not a date,-2.00,Broken too,Checking',
        ]);

        const merged = mergeImportFiles([first, second], 'en-US');
        const normalized = normalizeRows(
            merged,
            defaultMapping('generic', merged),
            options,
        );

        expect(merged.rowNumbers).toEqual([2, 3, 2, 4]);
        expect(normalized.unreadable).toEqual([
            {
                rowNumber: 3,
                fileName: 'part-1.csv',
                reason: "Date can't be read",
            },
            {
                rowNumber: 4,
                fileName: 'part-2.csv',
                reason: "Date can't be read",
            },
        ]);
        // Numbering starting over in the second file is no blank row.
        expect(normalized.blankRows).toBe(1);
    });

    it('returns a single file as it is', async () => {
        const only = await read('export.csv', [
            HEADER,
            'not a date,-1.00,Broken,Checking',
        ]);

        expect(mergeImportFiles([only])).toBe(only);
        expect(importFileName(only)).toBe('export.csv');
        expect(
            normalizeRows(only, defaultMapping('generic', only), options)
                .unreadable,
        ).toEqual([{ rowNumber: 2, reason: "Date can't be read" }]);
    });

    it('detects the source on the merged headers', async () => {
        const banktrack = 'Fecha,Concepto,Importe,Banco,Categorías,Ignorada,ID';
        const first = await read('banktrack-1.csv', [
            banktrack,
            '02/01/2026,Café,-5,MyBank,Comida,FALSE,a1',
        ]);
        const second = await read('banktrack-2.csv', [
            banktrack,
            '03/01/2026,Pan,-2,MyBank,Comida,FALSE,a2',
        ]);

        const merged = mergeImportFiles([first, second]);

        expect(detectSource(merged.headers)).toBe('banktrack');
        expect(importFileName(merged)).toBe('banktrack-1.csv, banktrack-2.csv');
    });
});

describe('the files picked for an import', () => {
    async function picked(name: string, lines: string[]): Promise<PickedFile> {
        const file = csv(name, lines);

        return { file, read: await parseImportFile(file), error: null };
    }

    it('turns away a file whose headers differ from the first, by name', async () => {
        const first = await picked('part-1.csv', [
            HEADER,
            '2026-01-02,-5.00,Coffee,Checking',
        ]);
        const other = await picked('other.csv', [
            'Date,Amount,Memo',
            '2026-01-02,-5.00,Coffee',
        ]);
        const all = [first, other];

        expect(fileProblem(first, all)).toBeNull();
        expect(fileProblem(other, all)).toBe(
            "other.csv has different columns from part-1.csv, so it can't be imported as part of the same export.",
        );
        expect(importableReads(all)).toEqual([first.read]);
        // Once the first is gone, the other one sets the headers instead.
        expect(fileProblem(other, [other])).toBeNull();
    });

    it('keeps a read failure as the reason', () => {
        const broken: PickedFile = {
            file: csv('broken.csv', []),
            read: null,
            error: 'There are no rows in it',
        };

        expect(fileProblem(broken, [broken])).toBe('There are no rows in it');
        expect(fileProblem(broken, [broken, broken])).toBe(
            'broken.csv: There are no rows in it',
        );
        expect(importableReads([broken])).toEqual([]);
    });

    it('tells the same file picked twice', () => {
        const file = new File(['a'], 'a.csv', { lastModified: 1 });

        expect(
            sameFile(file, new File(['a'], 'a.csv', { lastModified: 1 })),
        ).toBe(true);
        expect(
            sameFile(file, new File(['a'], 'b.csv', { lastModified: 1 })),
        ).toBe(false);
    });
});
