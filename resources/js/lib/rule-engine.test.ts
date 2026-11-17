import { evaluateRulesForNewTransaction } from '@/lib/rule-engine';
import type { AutomationRule } from '@/types/automation-rule';
import type { Label } from '@/types/label';
import type { UUID } from '@/types/uuid';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/debug', () => ({ consoleDebug: () => {} }));

function makeLabel(id: string, archivedAt: string | null = null): Label {
    return {
        id: id as UUID,
        user_id: 'user-1' as UUID,
        name: id,
        color: 'blue',
        created_at: '2026-01-01T00:00:00.000Z',
        updated_at: '2026-01-01T00:00:00.000Z',
        deleted_at: null,
        archived_at: archivedAt,
    };
}

function makeRule(labels: Label[]): AutomationRule {
    return {
        id: 'rule-1' as UUID,
        user_id: 'user-1' as UUID,
        title: 'Coffee',
        priority: 1,
        origin: 'user',
        rules_json: { in: ['coffee', { var: 'description' }] },
        action_category_id: 'cat-1' as UUID,
        action_note: null,
        labels,
        created_at: '2026-01-01T00:00:00.000Z',
        updated_at: '2026-01-01T00:00:00.000Z',
        deleted_at: null,
    };
}

function evaluate(rule: AutomationRule) {
    return evaluateRulesForNewTransaction(
        {
            description: 'Morning coffee',
            amount: -3,
            transaction_date: '2026-01-01',
            account_id: 'acc-1' as UUID,
        },
        [rule],
        [],
        [],
        [],
    );
}

describe('evaluateRulesForNewTransaction', () => {
    it('applies every label of a matching rule', () => {
        const result = evaluate(makeRule([makeLabel('a'), makeLabel('b')]));

        expect(result?.labelIds).toEqual(['a', 'b']);
    });

    it('skips archived labels but keeps the rule’s other actions', () => {
        const archived = makeLabel('old', '2026-02-01T00:00:00.000Z');
        const result = evaluate(makeRule([makeLabel('a'), archived]));

        expect(result?.categoryId).toBe('cat-1');
        expect(result?.labelIds).toEqual(['a']);
        expect(result?.labels.map((label) => label.id)).toEqual(['a']);
    });
});
