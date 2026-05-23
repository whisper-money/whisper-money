import { ApplyCategorizerAutomationRulesDialog } from '@/components/automation-rules/apply-categorizer-automation-rules-dialog';
import type { RuleEvaluationResult } from '@/lib/rule-engine';
import type { AutomationRule } from '@/types/automation-rule';
import type { Category } from '@/types/category';
import type { DecryptedTransaction } from '@/types/transaction';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const groceryCategory: Category = {
    id: 'cat-grocery',
    name: 'Groceries',
    icon: 'ShoppingBasket',
    color: 'green',
    type: 'expense',
    cashflow_direction: 'outflow',
};

const groceryRule: AutomationRule = {
    id: 'rule-1',
    user_id: 'user-id',
    title: 'Grocery stores',
    priority: 0,
    rules_json: {},
    action_category_id: groceryCategory.id,
    action_note: null,
    action_note_iv: null,
    category: groceryCategory,
    labels: [],
    created_at: '2026-05-22T00:00:00.000Z',
    updated_at: '2026-05-22T00:00:00.000Z',
    deleted_at: null,
};

const transaction: DecryptedTransaction = {
    id: 'tx-1',
    user_id: 'user-id',
    account_id: 'account-id',
    category_id: null,
    description: 'encrypted-description',
    decryptedDescription: 'Whole Foods Market',
    description_iv: 'iv',
    transaction_date: '2026-05-22',
    amount: -2450,
    currency_code: 'USD',
    notes: null,
    decryptedNotes: null,
    notes_iv: null,
    source: 'imported',
    created_at: '2026-05-22T00:00:00.000Z',
    updated_at: '2026-05-22T00:00:00.000Z',
};

const result: RuleEvaluationResult = {
    rule: groceryRule,
    categoryId: groceryCategory.id,
    labelIds: [],
    labels: [],
    note: null,
    noteIv: null,
};

describe('ApplyCategorizerAutomationRulesDialog', () => {
    it('previews affected transactions before applying', () => {
        const onApply = vi.fn();

        render(
            <ApplyCategorizerAutomationRulesDialog
                open={true}
                matches={[{ transaction, result }]}
                categories={[groceryCategory]}
                applying={false}
                onOpenChange={vi.fn()}
                onApply={onApply}
            />,
        );

        expect(
            screen.getByText('Apply rules to remaining transactions?'),
        ).toBeTruthy();
        expect(screen.getByText('Whole Foods Market')).toBeTruthy();
        expect(screen.getByText('Uncategorized')).toBeTruthy();
        expect(screen.getByText('Groceries')).toBeTruthy();

        fireEvent.click(screen.getByText('Apply to 1 transaction(s)'));

        expect(onApply).toHaveBeenCalledOnce();
    });
});
