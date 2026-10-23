import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    changedSuggestedFields,
    fetchColumnSuggestions,
    mergeSuggestion,
    pickFields,
    suggestedBalanceMapping,
    suggestedTransactionMapping,
    TRANSACTION_FIELDS,
    type ColumnSuggestions,
} from './ai-import-mapping';

vi.mock('axios', () => ({ default: { post: vi.fn() } }));

const post = vi.mocked(axios.post);

const columns = [
    { header: 'Datum', shape: 'date (dd-mm-yyyy)' },
    {
        header: 'Naam / Omschrijving',
        shape: 'text, avg 24 chars, mostly distinct values',
    },
    { header: 'Af Bij', shape: 'text, avg 2 chars, 2 distinct values' },
    {
        header: 'Bedrag (EUR)',
        shape: 'number, 2 decimals with ",", all positive',
    },
];

function suggestion(
    fields: Record<string, [string | null, number | null]>,
    cached = false,
): ColumnSuggestions {
    return {
        cached,
        fields: Object.fromEntries(
            Object.entries(fields).map(([field, [column, index]]) => [
                field,
                { column, index, confidence: 0.9 },
            ]),
        ),
    };
}

describe('fetchColumnSuggestions', () => {
    beforeEach(() => post.mockReset());

    it('sends only the headers and shapes, with the account when there is one', async () => {
        post.mockResolvedValue({
            data: {
                data: {
                    amount: {
                        column: 'Bedrag (EUR)',
                        index: 3,
                        confidence: 0.9,
                    },
                },
                cached: true,
            },
        });

        const result = await fetchColumnSuggestions(
            'transaction',
            columns,
            'account-1',
        );

        expect(post).toHaveBeenCalledWith('/api/import/column-suggestions', {
            kind: 'transaction',
            columns,
            account_id: 'account-1',
        });
        expect(result).toEqual({
            fields: {
                amount: { column: 'Bedrag (EUR)', index: 3, confidence: 0.9 },
            },
            cached: true,
        });
    });

    it('leaves the account out when there is none', async () => {
        post.mockResolvedValue({ data: { data: {}, cached: false } });

        await fetchColumnSuggestions('balance', columns, null);

        expect(post).toHaveBeenCalledWith('/api/import/column-suggestions', {
            kind: 'balance',
            columns,
        });
    });

    it('answers nothing when the AI could not, or the request failed', async () => {
        post.mockResolvedValueOnce({ data: { data: null, cached: false } });
        post.mockRejectedValueOnce(new Error('403'));

        expect(await fetchColumnSuggestions('transaction', columns)).toBeNull();
        expect(await fetchColumnSuggestions('transaction', columns)).toBeNull();
    });

    it('does not ask about a file without columns', async () => {
        expect(await fetchColumnSuggestions('transaction', [])).toBeNull();
        expect(post).not.toHaveBeenCalled();
    });
});

describe('suggestedTransactionMapping', () => {
    it('keeps the type column when the amounts carry no sign', () => {
        expect(
            suggestedTransactionMapping(
                suggestion({
                    transaction_date: ['Datum', 0],
                    description: ['Naam / Omschrijving', 1],
                    amount: ['Bedrag (EUR)', 3],
                    transaction_type: ['Af Bij', 2],
                    currency: [null, null],
                }),
                columns,
            ),
        ).toMatchObject({
            transaction_date: 'Datum',
            amount: 'Bedrag (EUR)',
            transaction_type: 'Af Bij',
            currency: null,
        });
    });

    it('drops the type column when the amounts are signed', () => {
        const signed = columns.map((column) =>
            column.header === 'Bedrag (EUR)'
                ? {
                      ...column,
                      shape: 'number, 2 decimals with ",", mixed signs',
                  }
                : column,
        );

        expect(
            suggestedTransactionMapping(
                suggestion({
                    amount: ['Bedrag (EUR)', 3],
                    transaction_type: ['Af Bij', 2],
                }),
                signed,
            ).transaction_type,
        ).toBeNull();
    });

    it('leaves a required field it could not place to the header guess', () => {
        const mapping = suggestedTransactionMapping(
            suggestion({
                transaction_date: [null, null],
                balance: [null, null],
            }),
            columns,
        );

        expect(mapping).not.toHaveProperty('transaction_date');
        expect(mapping.balance).toBeNull();
    });
});

describe('suggestedBalanceMapping', () => {
    it('keeps the required balance fields it could not place out of the suggestion', () => {
        expect(
            suggestedBalanceMapping(
                suggestion({
                    balance_date: ['Datum', 0],
                    balance: [null, null],
                    invested_amount: [null, null],
                }),
            ),
        ).toEqual({ balance_date: 'Datum', invested_amount: null });
    });
});

describe('mergeSuggestion', () => {
    it('lays the suggestion over every field the user has not picked', () => {
        expect(
            mergeSuggestion(
                { amount: 'Fee', description: 'Concept', currency: 'CCY' },
                { amount: 'Amount', description: 'Narrative', currency: null },
                new Set(['description']),
            ),
        ).toEqual({ amount: 'Amount', description: 'Concept', currency: null });
    });
});

describe('changedSuggestedFields', () => {
    it('counts the suggested fields the mapping no longer has as suggested', () => {
        const suggested = {
            amount: 'Amount',
            description: 'Narrative',
            currency: null,
        };

        expect(changedSuggestedFields({ ...suggested }, suggested)).toBe(0);
        expect(
            changedSuggestedFields(
                { amount: 'Amount', description: ['Narrative'], currency: '' },
                suggested,
            ),
        ).toBe(0);
        expect(
            changedSuggestedFields(
                {
                    amount: 'Balance',
                    description: ['Narrative', 'Notes'],
                    currency: null,
                },
                suggested,
            ),
        ).toBe(2);
    });
});

describe('pickFields', () => {
    it('keeps only the fields the AI places', () => {
        expect(
            pickFields(
                { transaction_date: 'Datum', expense_types: ['Af'] },
                TRANSACTION_FIELDS,
            ),
        ).toEqual({
            transaction_date: 'Datum',
            description: null,
            amount: null,
            currency: null,
            balance: null,
            creditor_name: null,
            debtor_name: null,
            transaction_type: null,
        });
    });
});
