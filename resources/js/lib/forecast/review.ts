import {
    type ForecastAnnualSuggestion,
    type ForecastReviewEstimate,
} from '@/types/forecast';
import { formatMonthName } from '@/utils/date';
import { __ } from '@/utils/i18n';

/** A category row of the first step, unique across accounts and sides. */
export function reviewEstimateId(row: ForecastReviewEstimate): string {
    return `${row.accountId}|${row.kind}|${row.categoryId ?? 'other'}`;
}

/** A first-step row's name: its category's, or "Other income" / "Other spending". */
export function estimateName(row: ForecastReviewEstimate): string {
    if (row.name !== null) {
        return row.name;
    }

    return row.kind === 'income' ? __('Other income') : __('Other spending');
}

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
    /** The keys of the yearly suggestions added. */
    annual: string[];
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
 * What the review sends: the yearly suggestions added, the ones not added
 * as dismissed, and the payments typed in with an amount, signed and in
 * minor units.
 */
export function reviewAnswers(
    annual: ForecastAnnualSuggestion[],
    addedAnnual: Set<string>,
    manual: ManualPaymentDraft[],
): ReviewAnswers {
    return {
        annual: annual
            .filter((suggestion) => addedAnnual.has(suggestion.key))
            .map((suggestion) => suggestion.key),
        dismissed: annual
            .filter((suggestion) => !addedAnnual.has(suggestion.key))
            .map((suggestion) => suggestion.key),
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
