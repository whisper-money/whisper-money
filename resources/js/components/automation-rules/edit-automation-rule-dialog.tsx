import { update } from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
import { RuleBuilder } from '@/components/automation-rules/rule-builder';
import { CategoryCombobox } from '@/components/shared/category-combobox';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    buildJsonLogic,
    createEmptyGroup,
    isValidRuleStructure,
    parseJsonLogic,
    type RuleStructure,
} from '@/lib/rule-builder-utils';
import type { AutomationRule } from '@/types/automation-rule';
import type { Category } from '@/types/category';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface EditAutomationRuleDialogProps {
    rule: AutomationRule;
    categories: Category[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess?: () => void;
}

export function EditAutomationRuleDialog({
    rule,
    categories,
    open,
    onOpenChange,
    onSuccess,
}: EditAutomationRuleDialogProps) {
    const [title, setTitle] = useState('');
    const [ruleStructure, setRuleStructure] = useState<RuleStructure>({
        groups: [createEmptyGroup()],
        groupOperator: 'and',
    });
    const [categoryId, setCategoryId] = useState<string>('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (rule && open) {
            setTitle(rule.title);
            setRuleStructure(parseJsonLogic(rule.rules_json));
            setCategoryId(
                rule.action_category_id ? String(rule.action_category_id) : '',
            );
        }
    }, [rule, open]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setErrors({});

        if (!title.trim()) {
            setErrors((prev) => ({ ...prev, title: 'Title is required' }));
            return;
        }

        if (!isValidRuleStructure(ruleStructure)) {
            setErrors((prev) => ({
                ...prev,
                rules_json: 'At least one valid condition is required',
            }));
            return;
        }

        if (!categoryId) {
            setErrors((prev) => ({
                ...prev,
                action_category_id: 'A category is required',
            }));
            return;
        }

        setIsSubmitting(true);

        try {
            const jsonLogic = buildJsonLogic(ruleStructure);

            router.patch(
                update(rule.id).url,
                {
                    title: title.trim(),
                    priority: rule.priority,
                    rules_json: JSON.stringify(jsonLogic),
                    action_category_id: categoryId,
                    action_note: null,
                    action_note_iv: null,
                    action_label_ids: null,
                },
                {
                    preserveState: true,
                    preserveScroll: true,
                    onSuccess: () => {
                        onOpenChange(false);
                        setErrors({});
                        onSuccess?.();
                    },
                    onError: (errors) => {
                        setErrors(errors as Record<string, string>);
                    },
                    onFinish: () => {
                        setIsSubmitting(false);
                    },
                },
            );
        } catch (error) {
            console.error('Failed to update automation rule:', error);
            setIsSubmitting(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="overflow-x-hidden sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>{__('Edit Automation Rule')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'Update the rule to automatically categorize transactions.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="title">{__('Title')}</Label>
                        <Input
                            id="title"
                            value={title}
                            onChange={(e) => setTitle(e.target.value)}
                            placeholder={__('Rule title')}
                            required
                        />

                        {errors.title && (
                            <p className="text-sm text-red-500">
                                {errors.title}
                            </p>
                        )}
                    </div>

                    <RuleBuilder
                        value={ruleStructure}
                        onChange={setRuleStructure}
                        error={errors.rules_json}
                    />

                    <div className="space-y-4 rounded-md border p-4">
                        <h4 className="font-medium">{__('Actions')}</h4>

                        <div className="space-y-2">
                            <Label htmlFor="category">
                                {__('Set Category')}
                            </Label>
                            <CategoryCombobox
                                value={categoryId}
                                onValueChange={setCategoryId}
                                categories={categories}
                                placeholder={__('Select a category')}
                                showUncategorized={false}
                                data-testid="action-category-select"
                            />
                        </div>

                        {errors.action_category_id && (
                            <p className="text-sm text-red-500">
                                {errors.action_category_id}
                            </p>
                        )}
                    </div>

                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={isSubmitting}
                        >
                            {__('Cancel')}
                        </Button>
                        <Button
                            type="submit"
                            disabled={isSubmitting}
                            data-testid="submit-automation-rule"
                        >
                            {isSubmitting ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
