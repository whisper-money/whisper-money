import { detectDateFormat } from '@/lib/file-parser';
import { normalizeText } from '@/lib/full-import-profiles';
import { type ParsedImportFile } from '@/lib/transaction-import';
import { type ParsedRow } from '@/types/import';
import { __ } from '@/utils/i18n';

/**
 * The headers as the profiles compare them, order aside: "Fecha" and
 * "fecha " are the same column, and a file that lists them in another order
 * still holds the same columns.
 */
function headerSignature(headers: string[]): string {
    return headers
        .map((header) => normalizeText(header))
        .sort()
        .join('\n');
}

/** Both files carry the same columns, so their rows can be read as one. */
export function sameHeaders(a: string[], b: string[]): boolean {
    return headerSignature(a) === headerSignature(b);
}

/** A file the user picked for the import, read or turned away. */
export interface PickedFile {
    file: File;
    /** Null when it could not be read at all: `error` says why. */
    read: ParsedImportFile | null;
    error: string | null;
}

/**
 * Why a picked file stays out of the import, or null when it goes in, naming
 * the file whenever there are several. The first file that could be read
 * sets the headers; one whose headers differ belongs to another export, and
 * guessing how to line its columns up is not ours to do.
 */
export function fileProblem(
    one: PickedFile,
    picked: PickedFile[],
): string | null {
    if (one.error !== null) {
        // With several files in the list, the reason alone does not say
        // which one it is about.
        return picked.length > 1 ? `${one.file.name}: ${one.error}` : one.error;
    }

    const reference = picked.find((other) => other.read !== null);

    if (
        !one.read ||
        !reference?.read ||
        sameHeaders(reference.read.headers, one.read.headers)
    ) {
        return null;
    }

    return __(
        ":file has different columns from :first, so it can't be imported as part of the same export.",
        { file: one.file.name, first: reference.file.name },
    );
}

/** The reads of every picked file that goes into the import, in order. */
export function importableReads(picked: PickedFile[]): ParsedImportFile[] {
    return picked.flatMap((one) =>
        one.read && fileProblem(one, picked) === null ? [one.read] : [],
    );
}

/** A row of a later file under the first file's spelling of each header. */
function renameCells(
    row: ParsedRow,
    headerByName: Map<string, string>,
): ParsedRow {
    return Object.fromEntries(
        Object.entries(row).map(([header, value]) => [
            headerByName.get(normalizeText(header)) ?? header,
            value,
        ]),
    );
}

/**
 * One export split into several files (Money Pro cuts its CSV every 1000
 * rows) read as the single file it really is. The first file sets the
 * headers, the column examples and the name; every row keeps its own number
 * and the name of the file it came from, so a problem points to the right
 * spreadsheet. The files must share their headers: {@link sameHeaders}.
 *
 * A single file comes back as it is.
 */
export function mergeImportFiles(
    files: ParsedImportFile[],
    locale?: string,
): ParsedImportFile {
    const [first, ...rest] = files;

    if (rest.length === 0) {
        return first;
    }

    const headerByName = new Map(
        first.headers.map((header) => [normalizeText(header), header]),
    );
    const rows = files.flatMap((file, index) =>
        index === 0
            ? file.rows
            : file.rows.map((row) => renameCells(row, headerByName)),
    );
    const dateColumn = first.mapping.transaction_date;
    const detected = dateColumn
        ? detectDateFormat(rows, dateColumn, locale)
        : null;

    return {
        ...first,
        rows,
        rowNumbers: files.flatMap((file) => file.rowNumbers),
        rowFiles: files.flatMap((file) => file.rows.map(() => file.file.name)),
        dateFormat: detected?.format ?? first.dateFormat,
        dateFormatDetected: detected ? !detected.ambiguous : false,
        dateFormatAmbiguous: detected?.ambiguous ?? false,
    };
}

/**
 * What the import records as its file: the file's name, or every file's name
 * when the rows came from several, within what the server keeps.
 */
export function importFileName(
    parsed: Pick<ParsedImportFile, 'file' | 'rowFiles'>,
): string {
    if (!parsed.rowFiles) {
        return parsed.file.name;
    }

    // The server keeps 255 characters; past that the list is cut short.
    return [...new Set(parsed.rowFiles)].join(', ').slice(0, 255);
}
