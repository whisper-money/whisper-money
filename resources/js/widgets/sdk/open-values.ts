/**
 * A dialog of the app a widget can open over the dashboard, with what it
 * starts on. The host opens it (see `WidgetGesture.openDialog`); nothing is
 * written until the user confirms there.
 */
export interface WidgetDialogRequest {
    dialog: 'newTransaction' | 'import';
    /** The account to start on; null leaves the dialog's own default. */
    accountId: string | null;
}

/**
 * What a `wm.open()` destination resolves to: a page to visit, a dialog to
 * open in place, or why it is neither.
 */
export type Resolved =
    | { url: string }
    | { dialog: WidgetDialogRequest }
    | { error: string };

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export function isPlainObject(
    value: unknown,
): value is Record<string, unknown> {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return false;
    }

    const prototype = Object.getPrototypeOf(value);

    return prototype === Object.prototype || prototype === null;
}

export function isUuid(value: unknown): value is string {
    return typeof value === 'string' && UUID.test(value);
}

/** A real calendar day written `YYYY-MM-DD`. */
export function isDate(value: unknown): value is string {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return false;
    }

    const date = new Date(`${value}T00:00:00Z`);

    return (
        !Number.isNaN(date.getTime()) &&
        date.toISOString().slice(0, 10) === value
    );
}
