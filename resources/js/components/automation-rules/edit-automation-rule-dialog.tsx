import { AutomationRuleForm } from '@/components/automation-rules/automation-rule-form';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { RuleStructure } from '@/lib/rule-builder-utils';
import type { AutomationRule } from '@/types/automation-rule';
import type { Category } from '@/types/category';
import type { Label } from '@/types/label';
import { __ } from '@/utils/i18n';

interface EditAutomationRuleDialogProps {
    rule: AutomationRule;
    categories: Category[];
    labels: Label[];
    open: boolean;
    initialRuleStructure?: RuleStructure;
    onOpenChange: (open: boolean) => void;
    onSuccess?: () => void;
}

export function EditAutomationRuleDialog({
    rule,
    categories,
    labels,
    open,
    initialRuleStructure,
    onOpenChange,
    onSuccess,
}: EditAutomationRuleDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="overflow-x-hidden sm:max-w-[640px]">
                <DialogHeader>
                    <DialogTitle>{__('Edit Automation Rule')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'Update the rule to automatically categorize transactions and add labels.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <AutomationRuleForm
                    mode="edit"
                    rule={rule}
                    categories={categories}
                    labels={labels}
                    initialRuleStructure={initialRuleStructure}
                    onCancel={() => onOpenChange(false)}
                    onSuccess={() => {
                        onOpenChange(false);
                        onSuccess?.();
                    }}
                />
            </DialogContent>
        </Dialog>
    );
}
