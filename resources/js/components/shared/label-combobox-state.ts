import { type Label } from '@/types/label';

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
