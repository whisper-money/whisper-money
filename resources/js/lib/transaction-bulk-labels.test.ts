import { describe, expect, it } from 'vitest';

import { type Label } from '@/types/label';
import { type DecryptedTransaction } from '@/types/transaction';
import { applyBulkLabels } from './transaction-bulk-labels';

function label(id: string, name: string): Label {
    return {
        id,
        user_id: 'user-1',
        name,
        color: 'blue',
        created_at: '2026-05-11T00:00:00.000000Z',
        updated_at: '2026-05-11T00:00:00.000000Z',
        deleted_at: null,
    };
}

function transaction(
    overrides: Partial<DecryptedTransaction> = {},
): DecryptedTransaction {
    return {
        id: 'transaction-1',
        user_id: 'user-1',
        account_id: 'account-1',
        category_id: null,
        description: 'Coffee',
        description_iv: null,
        transaction_date: '2026-05-11',
        amount: -450,
        currency_code: 'EUR',
        notes: null,
        notes_iv: null,
        source: 'imported',
        label_ids: [],
        created_at: '2026-05-11T00:00:00.000000Z',
        updated_at: '2026-05-11T00:00:00.000000Z',
        decryptedDescription: 'Coffee',
        decryptedNotes: null,
        ...overrides,
    };
}

const work = label('label-1', 'Work');
const travel = label('label-2', 'Travel');
const allLabels = [work, travel];

describe('applyBulkLabels', () => {
    it('empties both label_ids and labels when clearing', () => {
        const labelled = transaction({
            label_ids: [work.id],
            labels: [work],
        });

        const [updated] = applyBulkLabels(
            [labelled],
            [labelled.id],
            [],
            allLabels,
        );

        expect(updated.label_ids).toEqual([]);
        expect(updated.labels).toEqual([]);
    });

    it('replaces the existing labels instead of merging them', () => {
        const labelled = transaction({
            label_ids: [work.id],
            labels: [work],
        });

        const [updated] = applyBulkLabels(
            [labelled],
            [labelled.id],
            [travel.id],
            allLabels,
        );

        expect(updated.label_ids).toEqual([travel.id]);
        expect(updated.labels).toEqual([travel]);
    });

    it('leaves unselected transactions untouched', () => {
        const selected = transaction({ id: 'transaction-1' });
        const untouched = transaction({
            id: 'transaction-2',
            label_ids: [work.id],
            labels: [work],
        });

        const [, result] = applyBulkLabels(
            [selected, untouched],
            [selected.id],
            [travel.id],
            allLabels,
        );

        expect(result).toBe(untouched);
    });

    it('ignores label ids that are not in the known labels', () => {
        const [updated] = applyBulkLabels(
            [transaction()],
            ['transaction-1'],
            [work.id, 'label-gone'],
            allLabels,
        );

        expect(updated.labels).toEqual([work]);
    });
});
