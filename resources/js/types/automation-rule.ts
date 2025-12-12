import type { Category } from './category';
import type { Label } from './label';
import { UUID } from './uuid';

export interface AutomationRule {
    id: UUID;
    user_id: UUID;
    title: string;
    priority: number;
    rules_json: Record<string, unknown>;
    action_category_id: UUID | null;
    action_note: string | null;
    action_note_iv: string | null;
    category?: Category;
    labels?: Label[];
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
}

export interface RuleAction {
    type: 'category' | 'note' | 'labels' | 'multiple';
    category?: Category;
    labels?: Label[];
    hasNote: boolean;
    hasLabels: boolean;
}

export function getRuleActions(rule: AutomationRule): RuleAction {
    const hasCategory = rule.action_category_id !== null;
    const hasNote = rule.action_note !== null;
    const hasLabels = (rule.labels?.length ?? 0) > 0;

    const actionCount =
        (hasCategory ? 1 : 0) + (hasNote ? 1 : 0) + (hasLabels ? 1 : 0);

    if (actionCount > 1) {
        return {
            type: 'multiple',
            category: rule.category,
            labels: rule.labels,
            hasNote,
            hasLabels,
        };
    }

    if (hasCategory) {
        return {
            type: 'category',
            category: rule.category,
            hasNote: false,
            hasLabels: false,
        };
    }

    if (hasLabels) {
        return {
            type: 'labels',
            labels: rule.labels,
            hasNote: false,
            hasLabels: true,
        };
    }

    return {
        type: 'note',
        hasNote: true,
        hasLabels: false,
    };
}

export function formatRuleActions(rule: AutomationRule): string {
    const actions = getRuleActions(rule);
    const parts: string[] = [];

    if (actions.category) {
        parts.push(actions.category.name);
    }

    if (actions.hasLabels && actions.labels) {
        const labelNames = actions.labels.map((l) => l.name).join(', ');
        parts.push(`labels: ${labelNames}`);
    }

    if (actions.hasNote) {
        parts.push('add note');
    }

    return parts.length > 0 ? parts.join(' + ') : 'No actions';
}
