import { store } from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
import { RuleBuilder } from '@/components/automation-rules/rule-builder';
import { CategoryCombobox } from '@/components/shared/category-combobox';
import { Button } from '@/components/ui/button';
import { CreateButton } from '@/components/ui/create-button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label as FormLabel } from '@/components/ui/label';
import {
    buildJsonLogic,
    createEmptyGroup,
    isValidRuleStructure,
    type RuleStructure,
} from '@/lib/rule-builder-utils';
import type { Category } from '@/types/category';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';

interface CreateAutomationRuleDialogProps {
    categories: Category[];
    disabled?: boolean;
    onSuccess?: () => void;
}

export function CreateAutomationRuleDialog({
    categories,
    disabled = false,
    onSuccess,
}: CreateAutomationRuleDialogProps) {
    const [open, setOpen] = useState(false);
    const [title, setTitle] = useState('');
    const [ruleStructure, setRuleStructure] = useState<RuleStructure>({
        groups: [createEmptyGroup()],
        groupOperator: 'or',
    });
    const [categoryId, setCategoryId] = useState<string>('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

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

            router.post(
                store().url,
                {
                    title: title.trim(),
                    priority: 0,
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
                        setOpen(false);
                        setTitle('');
                        setRuleStructure({
                            groups: [createEmptyGroup()],
                            groupOperator: 'and',
                        });
                        setCategoryId('');
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
            console.error('Failed to create automation rule:', error);
            setIsSubmitting(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <CreateButton disabled={disabled}>
                    {__('Create Rule')}
                </CreateButton>
            </DialogTrigger>
            <DialogContent className="overflow-x-hidden sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>{__('Create Automation Rule')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'Create a rule to automatically categorize transactions.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <FormLabel htmlFor="title">{__('Title')}</FormLabel>
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
                            <FormLabel htmlFor="category">
                                {__('Set Category')}
                            </FormLabel>
                            <div className="mt-1">
                                <CategoryCombobox
                                    value={categoryId}
                                    onValueChange={setCategoryId}
                                    categories={categories}
                                    placeholder={__('Select a category')}
                                    showUncategorized={false}
                                    data-testid="action-category-select"
                                />
                            </div>
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
                            onClick={() => setOpen(false)}
                            disabled={isSubmitting}
                        >
                            {__('Cancel')}
                        </Button>
                        <Button
                            type="submit"
                            disabled={isSubmitting}
                            data-testid="submit-automation-rule"
                        >
                            {isSubmitting ? 'Creating...' : 'Create'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
