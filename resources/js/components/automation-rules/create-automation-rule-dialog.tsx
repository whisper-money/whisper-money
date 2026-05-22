import { AutomationRuleForm } from '@/components/automation-rules/automation-rule-form';
import { CreateButton } from '@/components/ui/create-button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { RuleStructure } from '@/lib/rule-builder-utils';
import type { Category } from '@/types/category';
import type { Label } from '@/types/label';
import { __ } from '@/utils/i18n';
import { type ReactNode, useState } from 'react';

interface CreateAutomationRuleDialogProps {
    categories: Category[];
    labels: Label[];
    disabled?: boolean;
    initialTitle?: string;
    initialRuleStructure?: RuleStructure;
    initialCategoryId?: string;
    trigger?: ReactNode;
    onSuccess?: () => void;
}

export function CreateAutomationRuleDialog({
    categories,
    labels,
    disabled = false,
    initialTitle,
    initialRuleStructure,
    initialCategoryId,
    trigger,
    onSuccess,
}: CreateAutomationRuleDialogProps) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {trigger ?? (
                    <CreateButton disabled={disabled}>
                        {__('Create Rule')}
                    </CreateButton>
                )}
            </DialogTrigger>
            <DialogContent className="overflow-x-hidden sm:max-w-[640px]">
                <DialogHeader>
                    <DialogTitle>{__('Create Automation Rule')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'Create a rule to automatically categorize transactions and add labels.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <AutomationRuleForm
                    mode="create"
                    categories={categories}
                    labels={labels}
                    initialTitle={initialTitle}
                    initialRuleStructure={initialRuleStructure}
                    initialCategoryId={initialCategoryId}
                    onCancel={() => setOpen(false)}
                    onSuccess={() => {
                        setOpen(false);
                        onSuccess?.();
                    }}
                />
            </DialogContent>
        </Dialog>
    );
}
