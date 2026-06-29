import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { type AutomationRule, type RuleOrigin } from '@/types/automation-rule';
import { AutomationRuleTitle } from './automation-rule-title';

function makeRule(origin: RuleOrigin): AutomationRule {
    return {
        id: 'rule-1',
        user_id: 'user-1',
        title: 'Supermarket',
        priority: 1,
        origin,
        rules_json: {},
        action_category_id: null,
        action_note: null,
        action_note_iv: null,
        labels: [],
        created_at: '2026-06-15T00:00:00Z',
        updated_at: '2026-06-15T00:00:00Z',
        deleted_at: null,
    };
}

describe('AutomationRuleTitle', () => {
    it('prefixes the title with the AI sparkle when the rule was created by AI', () => {
        render(<AutomationRuleTitle rule={makeRule('ai')} />);

        expect(screen.getByText('Supermarket')).toBeInTheDocument();
        expect(screen.getByLabelText('Created by AI')).toBeInTheDocument();
    });

    it('prefixes the title with the AI sparkle when the rule was learned from a correction', () => {
        render(<AutomationRuleTitle rule={makeRule('correction')} />);

        expect(screen.getByText('Supermarket')).toBeInTheDocument();
        expect(
            screen.getByLabelText('Learned from your correction'),
        ).toBeInTheDocument();
    });

    it('shows no marker for user-created rules', () => {
        render(<AutomationRuleTitle rule={makeRule('user')} />);

        expect(screen.getByText('Supermarket')).toBeInTheDocument();
        expect(
            screen.queryByLabelText('Created by AI'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByLabelText('Learned from your correction'),
        ).not.toBeInTheDocument();
    });
});
