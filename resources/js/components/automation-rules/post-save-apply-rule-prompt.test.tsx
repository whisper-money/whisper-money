import {
    PostSaveApplyRulePrompt,
    savedAutomationRuleFlashKey,
} from '@/components/automation-rules/post-save-apply-rule-prompt';
import type { AutomationRule } from '@/types/automation-rule';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));
vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: () => null,
}));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const RULE = {
    id: 'rule-1',
    title: 'Groceries',
    rules_json: {},
    action_category_id: null,
    labels: [],
} as unknown as AutomationRule;

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            automationRules: [RULE],
            flash: {
                saved_automation_rule_id: 'rule-1',
                saved_automation_rule_token: 'save-1',
            },
        },
    }),
}));

const MATCH = {
    id: 'tx-1',
    user_id: 'user-id',
    account_id: 'account-id',
    category_id: null,
    description: 'Grocery',
    transaction_date: '2026-05-22',
    amount: -1000,
    currency_code: 'USD',
    notes: null,
    source: 'imported',
};

function jsonResponse(body: unknown): Response {
    return {
        ok: true,
        status: 200,
        json: () => Promise.resolve(body),
    } as Response;
}

describe('savedAutomationRuleFlashKey', () => {
    it('changes when the same rule is saved again with a new token', () => {
        expect(
            savedAutomationRuleFlashKey({
                saved_automation_rule_id: 'rule-1',
                saved_automation_rule_token: 'first-save',
            }),
        ).toBe('rule-1:first-save');

        expect(
            savedAutomationRuleFlashKey({
                saved_automation_rule_id: 'rule-1',
                saved_automation_rule_token: 'second-save',
            }),
        ).toBe('rule-1:second-save');
    });

    it('falls back to rule id for older flash payloads', () => {
        expect(
            savedAutomationRuleFlashKey({
                saved_automation_rule_id: 'rule-1',
            }),
        ).toBe('rule-1:rule-1');
    });
});

describe('PostSaveApplyRulePrompt', () => {
    beforeEach(() => {
        vi.stubGlobal(
            'IntersectionObserver',
            vi.fn(() => ({ observe: vi.fn(), disconnect: vi.fn() })),
        );
        vi.stubGlobal(
            'fetch',
            vi.fn((url: string, init?: RequestInit) =>
                Promise.resolve(
                    init?.method === 'POST'
                        ? jsonResponse({ status: 'done', applied: 1 })
                        : jsonResponse({
                              data: [MATCH],
                              total: 1,
                              next_offset: null,
                          }),
                ),
            ),
        );
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('tells the page once the just-saved rule is applied, so it can refresh its rows', async () => {
        const onApplied = vi.fn();

        render(<PostSaveApplyRulePrompt onApplied={onApplied} />);

        fireEvent.click(await screen.findByText('Review matches'));
        fireEvent.click(await screen.findByText('Apply to 1 transaction(s)'));

        await waitFor(() => expect(onApplied).toHaveBeenCalledTimes(1));
    });

    it('does not refresh when the rule is not applied', async () => {
        const onApplied = vi.fn();

        render(<PostSaveApplyRulePrompt onApplied={onApplied} />);

        fireEvent.click(await screen.findByText('Skip for now'));

        expect(onApplied).not.toHaveBeenCalled();
    });
});
