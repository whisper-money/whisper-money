import { saveBlob } from '@/lib/save-blob';

/**
 * `wm.download(filename, content)`: the only way a widget hands data to the
 * user. Widgets cannot create links or blobs themselves, so this is also the
 * only way out of the page for their data, and it keeps two promises:
 *
 * - It only works inside a user gesture: a click (or a key that clicks) on
 *   the widget. `<UserWidget>` opens the gesture in the capture phase of the
 *   click and closes it once the click has bubbled back, so a download a
 *   widget starts from an effect, a timer or on mount is refused.
 * - The file is always a CSV, and every cell that a spreadsheet would read
 *   as a formula (`=`, `+`, `-`, `@`, a tab or a carriage return first) is
 *   defused with a leading `'`. A bank description is written by a third
 *   party, and `=HYPERLINK(...)` in one must not run when the user opens it.
 *
 * `content` is CSV text, rows of cells, or rows of objects (their keys make
 * the header). It returns whether the download started.
 */
export type DownloadContent =
    | string
    | ReadonlyArray<ReadonlyArray<unknown>>
    | ReadonlyArray<Readonly<Record<string, unknown>>>;

let insideGesture = false;

/**
 * Called by the runtime when a click on a widget starts (capture phase). The
 * timeout is the backstop: it fires once the whole dispatch is over, even if
 * a widget's handler threw before the click bubbled back.
 */
export function beginWidgetGesture(): void {
    insideGesture = true;
    setTimeout(endWidgetGesture, 0);
}

/** Called by the runtime once that click has bubbled back to the widget. */
export function endWidgetGesture(): void {
    insideGesture = false;
}

function isInsideGesture(): boolean {
    if (!insideGesture) {
        return false;
    }

    // Belt and braces where the browser can tell: the click must be a real one.
    const activation = (
        globalThis.navigator as { userActivation?: { isActive: boolean } }
    )?.userActivation;

    return activation === undefined || activation.isActive;
}

export function download(filename: unknown, content: unknown): boolean {
    if (!isInsideGesture()) {
        console.warn(
            'wm.download() only works inside a click on the widget; the download was blocked.',
        );

        return false;
    }

    const rows = toRows(content);

    if (rows === null) {
        return false;
    }

    saveBlob(
        new Blob(['\uFEFF', toCsv(rows)], { type: 'text/csv;charset=utf-8' }),
        csvFilename(filename),
    );

    return true;
}

/** A plain file name ending in `.csv`: no path, no control characters. */
export function csvFilename(filename: unknown): string {
    const base = String(filename ?? '')
        .replace(/\.csv$/i, '')
        // eslint-disable-next-line no-control-regex
        .replace(/[\u0000-\u001f\u007f/\\:*?"<>|]+/g, '-')
        .replace(/^[.\s-]+|[.\s-]+$/g, '')
        .slice(0, 100);

    return `${base || 'widget'}.csv`;
}

/** A cell as text, with what a spreadsheet would run as a formula defused. */
export function sanitizeCsvCell(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }

    if (typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }

    const text = typeof value === 'string' ? value : JSON.stringify(value);

    // A plain number written as text (`-42.10`) is data, not a formula.
    if (/^-?\d+(?:[.,]\d+)*$/.test(text)) {
        return text;
    }

    return /^[=+\-@\t\r]/.test(text) ? `'${text}` : text;
}

function toRows(content: unknown): unknown[][] | null {
    if (typeof content === 'string') {
        return parseCsv(content);
    }

    if (!Array.isArray(content)) {
        return null;
    }

    if (content.every((row) => Array.isArray(row))) {
        return content as unknown[][];
    }

    const records = content.filter(
        (row): row is Record<string, unknown> =>
            typeof row === 'object' && row !== null && !Array.isArray(row),
    );
    const header = [...new Set(records.flatMap((row) => Object.keys(row)))];

    return [
        header,
        ...records.map((row) =>
            header.map((key) =>
                Object.hasOwn(row, key) ? row[key] : undefined,
            ),
        ),
    ];
}

function toCsv(rows: unknown[][]): string {
    return rows
        .map((row) =>
            row
                .map((cell) => {
                    const text = sanitizeCsvCell(cell);

                    return /[",\n\r;]/.test(text)
                        ? `"${text.replace(/"/g, '""')}"`
                        : text;
                })
                .join(','),
        )
        .join('\r\n');
}

/**
 * CSV text back into cells (RFC 4180: quoted fields, doubled quotes, line
 * breaks inside quotes), so a widget that writes its own CSV still gets every
 * cell defused.
 */
export function parseCsv(text: string): string[][] {
    const rows: string[][] = [];
    let row: string[] = [];
    let cell = '';
    let quoted = false;

    for (let index = 0; index < text.length; index++) {
        const char = text[index];

        if (quoted) {
            if (char === '"' && text[index + 1] === '"') {
                cell += '"';
                index++;
            } else if (char === '"') {
                quoted = false;
            } else {
                cell += char;
            }
        } else if (char === '"' && cell === '') {
            quoted = true;
        } else if (char === ',') {
            row.push(cell);
            cell = '';
        } else if (char === '\n' || char === '\r') {
            if (char === '\r' && text[index + 1] === '\n') {
                index++;
            }
            row.push(cell);
            rows.push(row);
            row = [];
            cell = '';
        } else {
            cell += char;
        }
    }

    if (cell !== '' || row.length > 0) {
        row.push(cell);
        rows.push(row);
    }

    return rows;
}
