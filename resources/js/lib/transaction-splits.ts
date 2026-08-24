import type { Transaction } from '@/types/transaction';

/** One part of a split as the API takes it: signed amount, category, labels. */
export interface SplitInput {
    amount: number;
    category_id: string | null;
    label_ids: string[];
}

/** One row of the split dialog while the user is still filling it in. */
export interface SplitDraft {
    key: string;
    categoryId: string | null;
    /** Unsigned, in cents. 0 means "not filled in yet". */
    amount: number;
    labelIds: string[];
}

/**
 * A transaction can be split when it moved some money and is not already one
 * part of a split — parts are not split again, they are merged back first.
 */
export function canSplit(transaction: Transaction): boolean {
    return transaction.amount !== 0 && !isSplitPart(transaction);
}

export function isSplitPart(transaction: Transaction): boolean {
    return Boolean(transaction.split_parent_id);
}

const assignedCents = (drafts: SplitDraft[]): number =>
    drafts.reduce((total, draft) => total + draft.amount, 0);

/**
 * What is left of the original to hand out, unsigned. Negative once the drafts
 * overshoot.
 */
export function remainingCents(
    totalCents: number,
    drafts: SplitDraft[],
): number {
    return Math.abs(totalCents) - assignedCents(drafts);
}

/**
 * The one thing that has to be true to save: the parts add up to the original,
 * with nothing left over and no part still empty.
 */
export function isSplitBalanced(
    totalCents: number,
    drafts: SplitDraft[],
): boolean {
    return (
        drafts.length >= 2 &&
        drafts.every((draft) => draft.amount > 0) &&
        remainingCents(totalCents, drafts) === 0
    );
}

/**
 * The payload the API takes. Amounts are typed unsigned and get the original's
 * sign back here, which is why a part can never point the wrong way.
 */
export function toSplitPayload(
    totalCents: number,
    drafts: SplitDraft[],
): SplitInput[] {
    const sign = totalCents < 0 ? -1 : 1;

    return drafts.map((draft) => ({
        amount: sign * draft.amount,
        category_id: draft.categoryId,
        label_ids: draft.labelIds,
    }));
}
