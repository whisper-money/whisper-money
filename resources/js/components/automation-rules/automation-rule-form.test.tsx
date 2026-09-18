import type { RuleStructure } from '@/lib/rule-builder-utils';
import type { AutomationRule } from '@/types/automation-rule';
import type { Category } from '@/types/category';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { AutomationRuleForm } from './automation-rule-form';

const router = vi.hoisted(() => ({ post: vi.fn(), patch: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router,
    usePage: () => ({
        props: {
            locale: 'en-US',
            auth: { user: { currency_code: 'EUR' } },
        },
    }),
}));

const category: Category = {
    id: 'category-1',
    name: 'Groceries',
    icon: 'ShoppingBasket',
    color: 'blue',
    type: 'expense',
    cashflow_direction: 'outflow',
    parent_id: null,
};

const ruleStructure: RuleStructure = {
    groups: [
        {
            id: 'group-1',
            operator: 'and',
            conditions: [
                {
                    id: 'condition-1',
                    field: 'description',
                    operator: 'contains',
                    value: 'mercadona',
                },
            ],
        },
    ],
    groupOperator: 'and',
};

function makeRule(overrides: Partial<AutomationRule> = {}): AutomationRule {
    return {
        id: 'rule-1',
        user_id: 'user-1',
        title: 'Groceries',
        priority: 25,
        origin: 'user',
        rules_json: {},
        action_category_id: 'category-1',
        action_note: null,
        action_note_iv: null,
        labels: [],
        created_at: '2026-06-15T00:00:00Z',
        updated_at: '2026-06-15T00:00:00Z',
        deleted_at: null,
        ...overrides,
    };
}

function renderForm(props: Partial<Parameters<typeof AutomationRuleForm>[0]>) {
    render(
        <AutomationRuleForm
            mode="create"
            categories={[category]}
            labels={[]}
            initialTitle="Groceries"
            initialRuleStructure={ruleStructure}
            initialCategoryId="category-1"
            onCancel={vi.fn()}
            {...props}
        />,
    );
}

function priorityInput(): HTMLInputElement {
    return screen.getByLabelText('Priority') as HTMLInputElement;
}

function submit() {
    fireEvent.click(screen.getByTestId('submit-automation-rule'));
}

describe('AutomationRuleForm priority', () => {
    beforeEach(() => {
        router.post.mockClear();
        router.patch.mockClear();
    });

    it('starts a new rule at the default priority', () => {
        renderForm({});

        expect(priorityInput().value).toBe('0');

        submit();

        expect(router.post.mock.lastCall![1]).toMatchObject({ priority: 0 });
    });

    it('sends the priority the user typed', () => {
        renderForm({});

        fireEvent.change(priorityInput(), { target: { value: '15' } });
        submit();

        expect(router.post.mock.lastCall![1]).toMatchObject({ priority: 15 });
    });

    it('falls back to the default when the field is left empty', () => {
        renderForm({});

        fireEvent.change(priorityInput(), { target: { value: '' } });
        submit();

        expect(router.post.mock.lastCall![1]).toMatchObject({ priority: 0 });
    });

    it('prefills and sends the priority of the rule being edited', () => {
        renderForm({ mode: 'edit', rule: makeRule() });

        expect(priorityInput().value).toBe('25');

        fireEvent.change(priorityInput(), { target: { value: '5' } });
        submit();

        expect(router.patch.mock.lastCall![1]).toMatchObject({ priority: 5 });
    });
});
