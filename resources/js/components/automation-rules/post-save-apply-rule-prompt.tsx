import { ApplyAutomationRuleDialog } from '@/components/automation-rules/apply-automation-rule-dialog';
import type { AutomationRule } from '@/types/automation-rule';
import { usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

type SavedAutomationRuleFlash = {
    saved_automation_rule_id?: string | null;
    saved_automation_rule_token?: string | null;
};

export function savedAutomationRuleFlashKey(
    flash?: SavedAutomationRuleFlash,
): string | null {
    const ruleId = flash?.saved_automation_rule_id ?? null;

    if (!ruleId) {
        return null;
    }

    return `${ruleId}:${flash?.saved_automation_rule_token ?? ruleId}`;
}

/**
 * Watches the shared Inertia `flash.saved_automation_rule_id` value and
 * renders the apply-rule dialog when a rule was just saved.
 *
 * Mount this once at the page level (NOT inside per-row dialogs that may
 * remount during Inertia visits).
 */
export function PostSaveApplyRulePrompt() {
    const { automationRules, flash } = usePage<{
        automationRules: AutomationRule[];
        flash?: SavedAutomationRuleFlash;
    }>().props;

    const [activeRuleId, setActiveRuleId] = useState<string | null>(null);
    const [open, setOpen] = useState(false);
    const consumedFlashRef = useRef<string | null>(null);
    const flashedRuleId = flash?.saved_automation_rule_id ?? null;
    const flashKey = savedAutomationRuleFlashKey(flash);

    useEffect(() => {
        if (!flashedRuleId || !flashKey) {
            return;
        }
        if (consumedFlashRef.current === flashKey) {
            return;
        }
        consumedFlashRef.current = flashKey;
        setActiveRuleId(flashedRuleId);
        setOpen(true);
    }, [flashKey, flashedRuleId]);

    const activeRule = useMemo(
        () =>
            activeRuleId
                ? (automationRules.find((r) => r.id === activeRuleId) ?? null)
                : null,
        [automationRules, activeRuleId],
    );

    if (!activeRule) {
        return null;
    }

    return (
        <ApplyAutomationRuleDialog
            rule={activeRule}
            open={open}
            initialStep="prompt"
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) {
                    setActiveRuleId(null);
                }
            }}
        />
    );
}
