import type { Category } from './category';

export interface AutomationRule {
    id: number;
    user_id: number;
    title: string;
    priority: number;
    rules_json: Record<string, any>;
    action_category_id: number | null;
    action_note: string | null;
    action_note_iv: string | null;
    category?: Category;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
}

export interface RuleAction {
    type: 'category' | 'note' | 'both';
    category?: Category;
    hasNote: boolean;
}

export function getRuleActions(rule: AutomationRule): RuleAction {
    const hasCategory = rule.action_category_id !== null;
    const hasNote = rule.action_note !== null;

    if (hasCategory && hasNote) {
        return {
            type: 'both',
            category: rule.category,
            hasNote: true,
        };
    }

    if (hasCategory) {
        return {
            type: 'category',
            category: rule.category,
            hasNote: false,
        };
    }

    return {
        type: 'note',
        hasNote: true,
    };
}

export function formatRuleActions(rule: AutomationRule): string {
    const actions = getRuleActions(rule);

    if (actions.type === 'both') {
        return `${actions.category?.name} and add note`;
    }

    if (actions.type === 'category') {
        return actions.category?.name || '';
    }

    return 'Add note';
}



