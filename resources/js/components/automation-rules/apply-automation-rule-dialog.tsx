import { ApplyAutomationRuleFlow } from '@/components/automation-rules/apply-automation-rule-flow';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { AutomationRule } from '@/types/automation-rule';
import { __ } from '@/utils/i18n';

interface ApplyAutomationRuleDialogProps {
    rule: AutomationRule;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initialStep?: 'prompt' | 'preview';
}

export function ApplyAutomationRuleDialog({
    rule,
    open,
    onOpenChange,
    initialStep = 'preview',
}: ApplyAutomationRuleDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="overflow-x-hidden sm:max-w-[640px]">
                <DialogHeader>
                    <DialogTitle>
                        {__('Apply rule to existing transactions')}
                    </DialogTitle>
                    <DialogDescription>
                        {initialStep === 'prompt'
                            ? __(
                                  'The rule was saved. Optionally apply it to your existing transactions.',
                              )
                            : __(
                                  'Preview the transactions this rule will affect and apply its actions.',
                              )}
                    </DialogDescription>
                </DialogHeader>
                <ApplyAutomationRuleFlow
                    rule={rule}
                    initialStep={initialStep}
                    onClose={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}
