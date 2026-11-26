import { type ForecastSuggestion } from '@/types/forecast';
import { describe, expect, it } from 'vitest';
import {
    canMerge,
    draftAmount,
    initialDrafts,
    mergeDrafts,
    separateDraft,
    suggestionDetail,
} from './review';

function suggestion(
    key: string,
    overrides: Partial<ForecastSuggestion> = {},
): ForecastSuggestion {
    return {
        key,
        kind: 'recurring',
        mergeable: true,
        name: key.toUpperCase(),
        accountId: 'checking',
        accountName: 'Checking',
        destinationAccountId: null,
        destinationAccountName: null,
        destinationUnknown: false,
        currencyCode: 'EUR',
        frequency: 'monthly',
        anchorDay: 8,
        nextDate: '2026-12-08',
        amount: -1000,
        amountTolerance: 100,
        confidence: 'high',
        chargeCount: 12,
        expectedCount: 12,
        ...overrides,
    };
}

const netflix = suggestion('netflix', { amount: -1299, anchorDay: 15 });
const hbo = suggestion('hbo', { amount: -999, anchorDay: 16 });
const salary = suggestion('salary', { amount: 250000 });
const byKey = { netflix, hbo, salary };

describe('merging review rows', () => {
    it('merges rows of the same account, direction and schedule only', () => {
        expect(canMerge(netflix, hbo)).toBe(true);
        expect(canMerge(netflix, suggestion('rent', { anchorDay: 1 }))).toBe(
            false,
        );
        expect(
            canMerge(
                suggestion('a', { anchorDay: 30 }),
                suggestion('b', { anchorDay: 1 }),
            ),
        ).toBe(true);
        expect(
            canMerge(
                netflix,
                suggestion('water', { anchorDay: 15, frequency: 'bimonthly' }),
            ),
        ).toBe(false);
        expect(
            canMerge(
                netflix,
                suggestion('x', { anchorDay: 15, mergeable: false }),
            ),
        ).toBe(false);
        expect(canMerge(netflix, salary)).toBe(false);
        expect(
            canMerge(
                netflix,
                suggestion('x', { accountId: 'card', anchorDay: 15 }),
            ),
        ).toBe(false);
        expect(canMerge(netflix, netflix)).toBe(false);
    });

    it('folds one row into another, adding up their amounts', () => {
        const drafts = mergeDrafts(
            initialDrafts([netflix, hbo, salary]),
            'netflix',
            'hbo',
        );

        expect(drafts.map((draft) => draft.id)).toEqual(['netflix', 'salary']);
        expect(drafts[0].keys).toEqual(['netflix', 'hbo']);
        expect(draftAmount(drafts[0], byKey)).toBe(-2298);
    });

    it('splits a merged row back where it was', () => {
        const merged = mergeDrafts(
            initialDrafts([netflix, hbo, salary]),
            'netflix',
            'hbo',
        );
        merged[0].name = 'Streaming';

        const drafts = separateDraft(merged, 'netflix', byKey);

        expect(drafts.map((draft) => [draft.id, draft.name])).toEqual([
            ['netflix', 'Streaming'],
            ['hbo', 'HBO'],
            ['salary', 'SALARY'],
        ]);
    });
});

describe('describing a suggestion', () => {
    const euros = (amount: number) => `${amount / 100} €`;

    it('says a variable amount and how often it was charged', () => {
        expect(
            suggestionDetail(
                suggestion('power', {
                    amount: -7000,
                    amountTolerance: 1500,
                    chargeCount: 4,
                    expectedCount: 6,
                }),
                'en',
                euros,
            ),
        ).toBe(
            'Monthly · day 8 · Checking · variable amount (55 €–85 €) · 4 of 6 months',
        );
    });

    it('names the month of a yearly one and counts times', () => {
        expect(
            suggestionDetail(
                suggestion('ibi', {
                    frequency: 'yearly',
                    nextDate: '2027-04-10',
                    chargeCount: 2,
                    expectedCount: 2,
                }),
                'en',
                euros,
            ),
        ).toBe('Yearly · April · Checking · 2 of 2 times');
    });
});
