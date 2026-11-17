import { assignableLabels, isArchivedLabel, type Label } from '@/types/label';

export function normalizeLabelComboboxState(
    value?: string[] | null,
    labels?: Label[] | null,
): {
    value: string[];
    labels: Label[];
} {
    return {
        value: Array.isArray(value) ? value : [],
        labels: Array.isArray(labels) ? labels : [],
    };
}

export interface LabelComboboxOptions {
    /** The chips: every selected label, archived ones included. */
    selectedLabels: Label[];
    /** What the list offers to pick, by name: archived labels are left out. */
    options: Label[];
    /** An assignable label whose name is exactly the query. */
    exactMatch: Label | null;
    /**
     * An archived label not attached here whose name is exactly the query,
     * to explain why it isn't offered.
     */
    archivedMatch: Label | null;
    /** A label with exactly this name exists, archived or not. */
    nameTaken: boolean;
}

/**
 * Splits what the picker shows. An archived label stays on whatever already
 * carries it, so it keeps its chip and can be removed, but it is never offered
 * as an option: once removed it can't be added back.
 */
export function labelComboboxOptions(
    value: string[],
    labels: Label[],
    query: string,
): LabelComboboxOptions {
    const normalizedQuery = query.trim().toLowerCase();
    const exactMatch = normalizedQuery
        ? labels.find((label) => label.name.toLowerCase() === normalizedQuery)
        : undefined;

    return {
        selectedLabels: labels.filter((label) => value.includes(label.id)),
        options: assignableLabels(labels)
            .filter(
                (label) =>
                    !normalizedQuery ||
                    label.name.toLowerCase().includes(normalizedQuery),
            )
            .sort((a, b) => a.name.localeCompare(b.name)),
        exactMatch:
            exactMatch && !isArchivedLabel(exactMatch) ? exactMatch : null,
        archivedMatch:
            exactMatch &&
            isArchivedLabel(exactMatch) &&
            !value.includes(exactMatch.id)
                ? exactMatch
                : null,
        nameTaken: exactMatch !== undefined,
    };
}
