import {
    type ForecastAnnualSuggestion,
    type ForecastReviewEstimate,
} from '@/types/forecast';
import { describe, expect, it } from 'vitest';
import {
    annualEvidence,
    estimateName,
    type ManualPaymentDraft,
    reviewAnswers,
    reviewEstimateId,
} from './review';

const insurance: ForecastAnnualSuggestion = {
    key: 'annual|checking|car insurance',
    name: 'Car insurance',
    accountId: 'checking',
    accountName: 'Checking',
    currencyCode: 'EUR',
    nextDate: '2027-04-10',
    amount: -64000,
    evidence: [
        { date: '2025-04-12', amount: -61200 },
        { date: '2026-04-10', amount: -64000 },
    ],
};

const tax: ForecastAnnualSuggestion = {
    ...insurance,
    key: 'annual|checking|ibi',
    name: 'IBI',
};

function payment(overrides: Partial<ManualPaymentDraft>): ManualPaymentDraft {
    return {
        id: 'manual-1',
        presetId: 'other',
        name: 'School',
        amount: 30000,
        direction: 'out',
        month: 9,
        accountId: 'checking',
        frequency: 'yearly',
        ...overrides,
    };
}

function estimate(
    overrides: Partial<ForecastReviewEstimate> = {},
): ForecastReviewEstimate {
    return {
        accountId: 'checking',
        accountName: 'Checking',
        currencyCode: 'EUR',
        categoryId: null,
        name: null,
        kind: 'expense',
        amount: 1000,
        basis: 'median',
        budgetAmount: null,
        ...overrides,
    };
}

describe('the review answers', () => {
    it('adds the yearly suggestions ticked and dismisses the rest', () => {
        expect(reviewAnswers([insurance, tax], new Set([tax.key]), [])).toEqual(
            {
                annual: [tax.key],
                dismissed: [insurance.key],
                manual: [],
            },
        );
    });

    it('signs the payments typed in and leaves the incomplete ones out', () => {
        const answers = reviewAnswers([], new Set(), [
            payment({ name: ' School ' }),
            payment({ id: 'manual-2', name: 'Extra pay', direction: 'in' }),
            payment({ id: 'manual-3', amount: 0 }),
            payment({ id: 'manual-4', name: ' ' }),
        ]);

        expect(answers.manual).toEqual([
            {
                name: 'School',
                amount: -30000,
                month: 9,
                accountId: 'checking',
                frequency: 'yearly',
            },
            {
                name: 'Extra pay',
                amount: 30000,
                month: 9,
                accountId: 'checking',
                frequency: 'yearly',
            },
        ]);
    });
});

describe('a row of the first step', () => {
    it('is named after its category, or "Other" on its side', () => {
        expect(estimateName(estimate({ name: 'Groceries' }))).toBe('Groceries');
        expect(estimateName(estimate())).toBe('Other spending');
        expect(estimateName(estimate({ kind: 'income' }))).toBe('Other income');
    });

    it('keeps "Other income" and "Other spending" of each account apart', () => {
        const ids = [
            estimate(),
            estimate({ kind: 'income' }),
            estimate({ accountId: 'savings' }),
            estimate({ categoryId: 'groceries' }),
        ].map(reviewEstimateId);

        expect(new Set(ids).size).toBe(4);
    });
});

describe('the charges a yearly suggestion stands on', () => {
    it('reads them by month and year', () => {
        expect(
            annualEvidence(insurance, 'en', (amount) => `€${amount / 100}`),
        ).toBe('€612 in April 2025 and €640 in April 2026');
    });
});
