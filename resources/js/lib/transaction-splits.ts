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

type SplitCandidate = Pick<
    Transaction,
    'amount' | 'split_parent_id' | 'splits'
>;

/**
 * A transaction can be split when it moved some money and is not already one
 * part of a split — parts are not split again, they are merged back first.
 * One still split the old way (lines) cannot be split either until it is
 * converted into parts.
 */
export function canSplit(transaction: SplitCandidate): boolean {
    return (
        transaction.amount !== 0 &&
        !isSplitPart(transaction) &&
        !hasLegacySplitLines(transaction)
    );
}

export function isSplitPart(
    transaction: Pick<Transaction, 'split_parent_id'>,
): boolean {
    return Boolean(transaction.split_parent_id);
}

/** Split the old way: one row whose lines carry the categories. */
export function hasLegacySplitLines(
    transaction: Pick<Transaction, 'splits'>,
): boolean {
    return (transaction.splits?.length ?? 0) > 0;
}

/**
 * The id of the movement a row stands for: a part's original (soft-deleted on
 * the server, but still what a statement payment points at), or the row itself.
 */
export function movementIdOf(
    transaction: Pick<Transaction, 'id' | 'split_parent_id'>,
): string {
    return transaction.split_parent_id ?? transaction.id;
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
