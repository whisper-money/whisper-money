import { store as storeBalance } from '@/actions/App/Http/Controllers/AccountBalanceController';
import { getCsrfToken } from '@/lib/csrf';
import {
    autoDetectColumns,
    collectBalancesToImport,
    detectDateFormat,
    parseFile,
} from '@/lib/file-parser';
import { loadImportConfig } from '@/lib/import-config-storage';
import { evaluateRulesForNewTransaction } from '@/lib/rule-engine';
import { transactionSyncService } from '@/services/transaction-sync';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import {
    DateFormat,
    type ColumnMapping,
    type ColumnOption,
    type ParsedRow,
    type ParsedTransaction,
} from '@/types/import';
import { type UUID } from '@/types/uuid';
import { toMajorUnits } from '@/utils/currency';
import { __ } from '@/utils/i18n';

/**
 * Everything SheetJS can read for us. Apple Numbers files are a zipped IWA
 * bundle, but the same reader handles them, so they need no special casing
 * beyond being let through here.
 */
export const SUPPORTED_IMPORT_EXTENSIONS = [
    '.csv',
    '.xls',
    '.xlsx',
    '.numbers',
];

export function isSupportedImportFile(file: File | null | undefined): boolean {
    if (!file || !file.name) {
        return false;
    }

    const lastDotIndex = file.name.lastIndexOf('.');

    if (lastDotIndex === -1) {
        return false;
    }

    return SUPPORTED_IMPORT_EXTENSIONS.includes(
        file.name.toLowerCase().slice(lastDotIndex),
    );
}

/**
 * Parsing happens in the browser, so a file large enough to hang the tab is
 * turned away before it is read rather than after. A decade of movements is
 * comfortably inside this.
 */
export const MAX_IMPORT_FILE_BYTES = 10 * 1024 * 1024;

/**
 * What a file becomes once it has been read and guessed at: the rows, and the
 * mapping the user is asked to confirm. The reading happens in the browser —
 * the file itself is never uploaded.
 */
export interface ParsedImportFile {
    file: File;
    rows: ParsedRow[];
    /** Each row's own number in the file, so a problem can be found in a spreadsheet. */
    rowNumbers: number[];
    headers: string[];
    columnOptions: ColumnOption[];
    mapping: ColumnMapping;
    dateFormat: DateFormat;
    /** False while the format still needs confirming, either way it was reached. */
    dateFormatDetected: boolean;
    /**
     * The dates could be read more than one way. Kept apart from
     * `dateFormatDetected` because a stored layout settles an undetected format
     * but must not settle an ambiguous one: a wrong format saved last time is
     * exactly what the user needs the chance to correct.
     */
    dateFormatAmbiguous: boolean;
}

/** How many example cells a column offers as evidence of what it holds. */
const COLUMN_EXAMPLES = 3;

/**
 * Three values from the middle of the column rather than the top: the first
 * rows under a header are often blank or a carried-over balance, and neither
 * tells the user what the column actually holds.
 */
function buildColumnOptions(
    headers: string[],
    columns: unknown[][],
    headerRowIndex: number,
): ColumnOption[] {
    return headers.map((header, index) => {
        const columnData = columns[index] || [];
        const middleIndex = Math.floor(columnData.length / 2);
        const from = Math.max(headerRowIndex + 1, middleIndex);

        return {
            value: header,
            label: header,
            examples: columnData
                .slice(from, from + COLUMN_EXAMPLES)
                .filter(
                    (cell) =>
                        cell !== null &&
                        cell !== undefined &&
                        String(cell).trim() !== '',
                )
                .map((cell) => String(cell))
                .slice(0, COLUMN_EXAMPLES),
        };
    });
}

/**
 * Read a file and guess what its columns are. Throws whatever the parser threw
 * when the file is not something we can read at all.
 */
export async function parseImportFile(
    file: File,
    locale?: string,
): Promise<ParsedImportFile> {
    const { headers, data, rowNumbers, columns, headerRowIndex } =
        await parseFile(file);
    const mapping = autoDetectColumns(headers);

    let dateFormat = DateFormat.YearMonthDay;
    let dateFormatDetected = false;
    let dateFormatAmbiguous = false;

    if (mapping.transaction_date) {
        const detected = detectDateFormat(
            data,
            mapping.transaction_date,
            locale,
        );

        if (detected) {
            dateFormat = detected.format;
            dateFormatAmbiguous = detected.ambiguous;
            dateFormatDetected = !detected.ambiguous;
        }
    }

    return {
        file,
        rows: data,
        rowNumbers,
        headers,
        columnOptions: buildColumnOptions(headers, columns, headerRowIndex),
        mapping,
        dateFormat,
        dateFormatDetected,
        dateFormatAmbiguous,
    };
}

/** Every mapped column is one the file actually has. */
function mapsOnlyKnownColumns(
    mapping: ColumnMapping,
    headers: string[],
): boolean {
    return Object.values(mapping)
        .filter((column) => column !== null)
        .every((column) =>
            Array.isArray(column)
                ? column.every((one) => headers.includes(one as string))
                : headers.includes(column as string),
        );
}

/**
 * Overlay the layout this account was last imported with, so the second file
 * from the same bank is not mapped by hand again. A stored layout naming
 * columns this file does not have belongs to a different export and is ignored.
 *
 * Null when there is nothing worth applying, so a caller whose user has been
 * editing the mapping meanwhile can leave their edits alone.
 */
export async function applyStoredImportConfig(
    parsed: ParsedImportFile,
    accountId: UUID,
): Promise<ParsedImportFile | null> {
    const stored = await loadImportConfig(accountId);

    if (
        !stored ||
        !mapsOnlyKnownColumns(stored.columnMapping, parsed.headers)
    ) {
        return null;
    }

    return {
        ...parsed,
        mapping: { ...parsed.mapping, ...stored.columnMapping },
        dateFormat: stored.dateFormat,
        dateFormatDetected: !parsed.dateFormatAmbiguous,
    };
}

/** One row that could not be created, with enough of it to be recognised. */
export interface ImportRowError {
    rowNumber: number;
    transaction: {
        date: string;
        description: string;
        amount: string;
    };
    error: string;
}

export interface ImportOutcome {
    /** Aligned with the rows handed in: true for each one that made it. */
    imported: boolean[];
    errors: ImportRowError[];
    successCount: number;
    /** Of the rows that made it, how many no rule could categorize. */
    uncategorizedCount: number;
}

interface ImportTransactionsOptions {
    account: Account;
    /** The rows to create, already filtered down to what the user chose. */
    rows: ParsedTransaction[];
    /** Each row's number in the file. Defaults to its position in `rows`. */
    rowNumbers?: number[];
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    automationRules: AutomationRule[];
    onProgress?: (processed: number) => void;
}

/** Rows are created this many at a time, in parallel within the batch. */
const BATCH_SIZE = 20;

/** Apply the user's automation rules to a row about to be created. */
function categorizeRow(
    row: ParsedTransaction,
    options: ImportTransactionsOptions,
    rules: AutomationRule[],
): {
    categoryId: string | null;
    notes: string | null;
    labelIds: string[];
} {
    const { account, categories, accounts, banks } = options;

    const match = evaluateRulesForNewTransaction(
        {
            description: row.description,
            // A mapped CSV can carry a per-row currency, and the parser already
            // scaled the amount to it — so read it back the same way the save
            // below does, or a row in a different-scale currency matches the
            // wrong rules.
            amount: toMajorUnits(
                row.amount,
                row.currency_code ?? account.currency_code,
            ),
            transaction_date: row.transaction_date,
            account_id: account.id,
            creditor_name: row.creditor_name,
            debtor_name: row.debtor_name,
        },
        rules,
        categories,
        accounts,
        banks,
    );

    if (!match) {
        return { categoryId: null, notes: null, labelIds: [] };
    }

    return {
        categoryId: match.categoryId ?? null,
        notes: match.note ?? null,
        labelIds: match.labelIds ?? [],
    };
}

/** Write the balances the file carried, for the dates it carried them on. */
async function importBalances(
    account: Account,
    rows: ParsedTransaction[],
): Promise<void> {
    const balances = collectBalancesToImport(rows);

    if (balances.size === 0) {
        return;
    }

    try {
        for (const [balance_date, balance] of balances) {
            await fetch(storeBalance.url(account.id), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                    Accept: 'application/json',
                },
                body: JSON.stringify({ balance_date, balance }),
            });
        }
    } catch (error) {
        console.error('Failed to import balances:', error);
    }
}

/**
 * Create the chosen rows, in batches, reporting progress as it goes.
 *
 * A row that fails does not stop the ones after it — a bad row in the middle of
 * a year of history should cost the user that row, not the import. Which rows
 * made it comes back in `imported`, so a retry can leave them out: that is what
 * keeps a half-finished import from creating its successes a second time.
 */
export async function importTransactions(
    options: ImportTransactionsOptions,
): Promise<ImportOutcome> {
    const { account, rows, rowNumbers, automationRules, onProgress } = options;

    const imported = rows.map(() => false);
    const errors: ImportRowError[] = [];

    let successCount = 0;
    let uncategorizedCount = 0;
    let processed = 0;

    for (let start = 0; start < rows.length; start += BATCH_SIZE) {
        const batch = rows.slice(start, start + BATCH_SIZE);

        const results = await Promise.allSettled(
            batch.map(async (row) => {
                const { categoryId, notes, labelIds } =
                    automationRules.length > 0
                        ? categorizeRow(row, options, automationRules)
                        : { categoryId: null, notes: null, labelIds: [] };

                await transactionSyncService.create({
                    user_id:
                        (account as Account & { user_id?: string }).user_id ||
                        '00000000-0000-0000-0000-000000000000',
                    account_id: account.id,
                    category_id: categoryId,
                    description: row.description,
                    transaction_date: row.transaction_date,
                    amount: row.amount,
                    currency_code: row.currency_code ?? account.currency_code,
                    notes,
                    creditor_name: row.creditor_name ?? null,
                    debtor_name: row.debtor_name ?? null,
                    source: 'imported' as const,
                    label_ids: labelIds.length > 0 ? labelIds : undefined,
                });

                return { hasCategory: categoryId !== null };
            }),
        );

        results.forEach((result, batchIndex) => {
            const index = start + batchIndex;
            const row = batch[batchIndex];

            if (result.status === 'fulfilled') {
                imported[index] = true;
                successCount++;

                if (!result.value.hasCategory) {
                    uncategorizedCount++;
                }

                return;
            }

            const message =
                result.reason instanceof Error
                    ? result.reason.message
                    : __('Unknown error');

            console.error(`Transaction ${index + 1} failed:`, {
                transaction: row,
                error: result.reason,
                errorMessage: message,
            });

            errors.push({
                rowNumber: rowNumbers?.[index] ?? index + 1,
                transaction: {
                    date: row.transaction_date,
                    description: row.description,
                    amount: row.amount.toString(),
                },
                error: message,
            });
        });

        processed += batch.length;
        onProgress?.(processed);
    }

    await importBalances(account, rows);

    return { imported, errors, successCount, uncategorizedCount };
}
