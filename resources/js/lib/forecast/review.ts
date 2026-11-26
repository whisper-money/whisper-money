import {
    type ForecastAnnualSuggestion,
    type ForecastSuggestion,
    type ForecastSuggestionFrequency,
} from '@/types/forecast';
import { formatMonthName } from '@/utils/date';
import { __ } from '@/utils/i18n';

/**
 * A row of the review's first step: one suggestion, or several merged into
 * one line. `id` is the key of the suggestion it started from.
 */
export interface ReviewLineDraft {
    id: string;
    keys: string[];
    name: string;
    selected: boolean;
    /** For a transfer whose other side is not in the app: null leaves it. */
    destinationAccountId: string | null;
}

export type ReviewSection = 'income' | 'expenses' | 'transfers';

/** A payment typed in on the second step, the amount positive in minor units. */
export interface ManualPaymentDraft {
    id: string;
    presetId: QuickAddId;
    name: string;
    amount: number;
    direction: 'in' | 'out';
    month: number;
    accountId: string;
    frequency: 'yearly' | 'once';
}

export type QuickAddId =
    | 'home-insurance'
    | 'property-tax'
    | 'vehicle-tax'
    | 'health-insurance'
    | 'school'
    | 'tax-return'
    | 'extra-pay'
    | 'community-levy'
    | 'other';

export interface QuickAdd {
    id: QuickAddId;
    label: () => string;
    kind: () => string;
    direction: 'in' | 'out';
    frequency: 'yearly' | 'once';
    month: number;
    /** Whether the row lets the user say it is money in or out. */
    eitherWay?: boolean;
}

/** The shortcuts of the second step, in the order the design lists them. */
export const QUICK_ADDS: QuickAdd[] = [
    {
        id: 'home-insurance',
        label: () => __('Home insurance'),
        kind: () => __('Yearly expense'),
        direction: 'out',
        frequency: 'yearly',
        month: 1,
    },
    {
        id: 'property-tax',
        label: () => __('Property tax (IBI)'),
        kind: () => __('Yearly expense'),
        direction: 'out',
        frequency: 'yearly',
        month: 4,
    },
    {
        id: 'vehicle-tax',
        label: () => __('Vehicle tax'),
        kind: () => __('Yearly expense'),
        direction: 'out',
        frequency: 'yearly',
        month: 3,
    },
    {
        id: 'health-insurance',
        label: () => __('Health insurance'),
        kind: () => __('Yearly expense'),
        direction: 'out',
        frequency: 'yearly',
        month: 1,
    },
    {
        id: 'school',
        label: () => __('Enrolment or school fees'),
        kind: () => __('Yearly expense'),
        direction: 'out',
        frequency: 'yearly',
        month: 9,
    },
    {
        id: 'tax-return',
        label: () => __('Income tax return'),
        kind: () => __('Payment or refund'),
        direction: 'out',
        frequency: 'yearly',
        month: 6,
        eitherWay: true,
    },
    {
        id: 'extra-pay',
        label: () => __('Extra pay (income)'),
        kind: () => __('Yearly income'),
        direction: 'in',
        frequency: 'yearly',
        month: 6,
    },
    {
        id: 'community-levy',
        label: () => __('Community levy'),
        kind: () => __('One-off expense'),
        direction: 'out',
        frequency: 'once',
        month: 2,
    },
    {
        id: 'other',
        label: () => __('Other payment'),
        kind: () => __('Yearly expense'),
        direction: 'out',
        frequency: 'yearly',
        month: 1,
    },
];

export function sectionOf(suggestion: ForecastSuggestion): ReviewSection {
    if (suggestion.kind === 'transfer') {
        return 'transfers';
    }

    return suggestion.amount > 0 ? 'income' : 'expenses';
}

/** One row per suggestion, ticked only when the detection is sure of it. */
export function initialDrafts(
    suggestions: ForecastSuggestion[],
): ReviewLineDraft[] {
    return suggestions.map((suggestion) => ({
        id: suggestion.key,
        keys: [suggestion.key],
        name: suggestion.name,
        selected: suggestion.confidence === 'high',
        destinationAccountId: null,
    }));
}

/** How many days apart two charges may land and still be one line. */
const MERGE_WINDOW_DAYS = 3;

/**
 * Whether two rows can become one line, as the server checks it
 * (ForecastCandidate::canMergeWith()): the same account, direction and
 * schedule, days at most three apart (across the turn of the month), and
 * both recognised by their description. A transfer never merges.
 */
export function canMerge(
    a: ForecastSuggestion,
    b: ForecastSuggestion,
): boolean {
    const gap = Math.abs(a.anchorDay - b.anchorDay);

    return (
        a.key !== b.key &&
        a.mergeable &&
        b.mergeable &&
        a.accountId === b.accountId &&
        a.amount > 0 === b.amount > 0 &&
        a.frequency === b.frequency &&
        Math.min(gap, 31 - gap) <= MERGE_WINDOW_DAYS
    );
}

/** The first payment typed in that cannot be sent: no name or no amount. */
export function incompletePayment(
    manual: ManualPaymentDraft[],
): ManualPaymentDraft | null {
    return (
        manual.find(
            (payment) => payment.amount <= 0 || payment.name.trim() === '',
        ) ?? null
    );
}

/** `source` folded into `target`, which is kept ticked. */
export function mergeDrafts(
    drafts: ReviewLineDraft[],
    targetId: string,
    sourceId: string,
): ReviewLineDraft[] {
    const source = drafts.find((draft) => draft.id === sourceId);

    if (!source || sourceId === targetId) {
        return drafts;
    }

    return drafts
        .filter((draft) => draft.id !== sourceId)
        .map((draft) =>
            draft.id === targetId
                ? {
                      ...draft,
                      keys: [...draft.keys, ...source.keys],
                      selected: true,
                  }
                : draft,
        );
}

/** A merged row split back into one row per suggestion, where it was. */
export function separateDraft(
    drafts: ReviewLineDraft[],
    id: string,
    suggestions: Record<string, ForecastSuggestion>,
): ReviewLineDraft[] {
    return drafts.flatMap((draft) => {
        if (draft.id !== id || draft.keys.length < 2) {
            return [draft];
        }

        return draft.keys.map((key, index) => ({
            id: key,
            keys: [key],
            name: index === 0 ? draft.name : suggestions[key].name,
            selected: draft.selected,
            destinationAccountId: null,
        }));
    });
}

/** What the row shows: the suggestions' amounts added up. */
export function draftAmount(
    draft: ReviewLineDraft,
    suggestions: Record<string, ForecastSuggestion>,
): number {
    return draft.keys.reduce((sum, key) => sum + suggestions[key].amount, 0);
}

export function frequencyLabel(frequency: ForecastSuggestionFrequency): string {
    switch (frequency) {
        case 'monthly':
            return __('Monthly');
        case 'bimonthly':
            return __('Every two months');
        case 'quarterly':
            return __('Quarterly');
        case 'yearly':
            return __('Yearly');
        case 'once':
            return __('One-off');
    }
}

/**
 * Whether the charges moved well around their mean. Every suggestion gets at
 * least a tenth of its amount as tolerance, so only more than that counts.
 */
function isVariable(suggestion: ForecastSuggestion): boolean {
    return suggestion.amountTolerance > Math.abs(suggestion.amount) * 0.15;
}

/**
 * The row's detail: "Monthly · day 28 · Checking · 12 of 12 months", or for a
 * transfer "Monthly · day 1 · Checking → Savings".
 */
export function suggestionDetail(
    suggestion: ForecastSuggestion,
    locale: string,
    formatAmount: (amount: number) => string,
): string {
    const parts = [frequencyLabel(suggestion.frequency)];

    parts.push(
        suggestion.frequency === 'yearly'
            ? formatMonthName(suggestion.nextDate.slice(0, 7), locale)
            : __('day :day', { day: suggestion.anchorDay }),
    );

    if (suggestion.kind === 'transfer') {
        parts.push(
            `${suggestion.accountName ?? ''} → ${suggestion.destinationAccountName ?? __('another account')}`,
        );
    } else if (suggestion.accountName) {
        parts.push(suggestion.accountName);
    }

    if (isVariable(suggestion)) {
        const amount = Math.abs(suggestion.amount);

        parts.push(
            __('variable amount (:low–:high)', {
                low: formatAmount(amount - suggestion.amountTolerance),
                high: formatAmount(amount + suggestion.amountTolerance),
            }),
        );
    }

    parts.push(
        suggestion.frequency === 'monthly'
            ? __(':seen of :expected months', {
                  seen: suggestion.chargeCount,
                  expected: suggestion.expectedCount,
              })
            : __(':seen of :expected times', {
                  seen: suggestion.chargeCount,
                  expected: suggestion.expectedCount,
              }),
    );

    return parts.join(' · ');
}

/**
 * "612 € in April 2025 and 640 € in April 2026": the charges a yearly
 * suggestion stands on.
 */
export function annualEvidence(
    suggestion: ForecastAnnualSuggestion,
    locale: string,
    formatAmount: (amount: number) => string,
): string {
    const charges = suggestion.evidence.map((charge) =>
        __(':amount in :month', {
            amount: formatAmount(Math.abs(charge.amount)),
            month: `${formatMonthName(charge.date.slice(0, 7), locale)} ${charge.date.slice(0, 4)}`,
        }),
    );

    return charges.join(` ${__('and')} `);
}

/** Why the answers were not sent; `stale` when the suggestions changed meanwhile. */
export interface ReviewError {
    message: string;
    stale: boolean;
}

export interface ReviewAnswers {
    lines: {
        keys: string[];
        name: string;
        destinationAccountId: string | null;
    }[];
    dismissed: string[];
    manual: {
        name: string;
        amount: number;
        month: number;
        accountId: string;
        frequency: 'yearly' | 'once';
    }[];
}

/**
 * What the review sends: the ticked rows as lines, every other suggestion
 * (unticked rows and yearly ones not added) as dismissed, and the payments
 * typed in with an amount, signed and in minor units.
 */
export function reviewAnswers(
    drafts: ReviewLineDraft[],
    annual: ForecastAnnualSuggestion[],
    addedAnnual: Set<string>,
    manual: ManualPaymentDraft[],
): ReviewAnswers {
    const kept = drafts.filter((draft) => draft.selected);

    return {
        lines: [
            ...kept.map((draft) => ({
                keys: draft.keys,
                name: draft.name.trim(),
                destinationAccountId: draft.destinationAccountId,
            })),
            ...annual
                .filter((suggestion) => addedAnnual.has(suggestion.key))
                .map((suggestion) => ({
                    keys: [suggestion.key],
                    name: suggestion.name,
                    destinationAccountId: null,
                })),
        ],
        dismissed: [
            ...drafts
                .filter((draft) => !draft.selected)
                .flatMap((draft) => draft.keys),
            ...annual
                .filter((suggestion) => !addedAnnual.has(suggestion.key))
                .map((suggestion) => suggestion.key),
        ],
        manual: manual
            .filter(
                (payment) =>
                    payment.amount > 0 &&
                    payment.accountId !== '' &&
                    payment.name.trim() !== '',
            )
            .map((payment) => ({
                name: payment.name.trim(),
                amount:
                    payment.direction === 'in'
                        ? payment.amount
                        : -payment.amount,
                month: payment.month,
                accountId: payment.accountId,
                frequency: payment.frequency,
            })),
    };
}
