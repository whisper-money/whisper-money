import { savedAutomationRuleFlashKey } from '@/components/automation-rules/post-save-apply-rule-prompt';
import { describe, expect, it } from 'vitest';

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
