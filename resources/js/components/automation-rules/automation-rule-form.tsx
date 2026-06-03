import {
    store,
    update,
} from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
import { RuleBuilder } from '@/components/automation-rules/rule-builder';
import InputError from '@/components/input-error';
import { CategoryCombobox } from '@/components/shared/category-combobox';
import { LabelCombobox } from '@/components/shared/label-combobox';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label as FormLabel } from '@/components/ui/label';
import {
    buildJsonLogic,
    createEmptyGroup,
    isValidRuleStructure,
    parseJsonLogic,
    type RuleStructure,
} from '@/lib/rule-builder-utils';
import type { AutomationRule } from '@/types/automation-rule';
import type { Category } from '@/types/category';
import type { Label } from '@/types/label';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface AutomationRuleFormProps {
    mode: 'create' | 'edit';
    categories: Category[];
    labels: Label[];
    rule?: AutomationRule;
    initialTitle?: string;
    initialRuleStructure?: RuleStructure;
    initialCategoryId?: string;
    onCancel: () => void;
    onSuccess?: () => void;
}

export function AutomationRuleForm({
    mode,
    categories,
    labels,
    rule,
    initialTitle = '',
    initialRuleStructure,
    initialCategoryId = '',
    onCancel,
    onSuccess,
}: AutomationRuleFormProps) {
    const [title, setTitle] = useState(initialTitle);
    const [ruleStructure, setRuleStructure] = useState<RuleStructure>(
        initialRuleStructure ?? {
            groups: [createEmptyGroup()],
            groupOperator: 'or',
        },
    );
    const [categoryId, setCategoryId] = useState<string>(initialCategoryId);
    const [labelIds, setLabelIds] = useState<string[]>([]);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (mode === 'edit' && rule) {
            setTitle(initialTitle || rule.title);
            setRuleStructure(
                initialRuleStructure ?? parseJsonLogic(rule.rules_json),
            );
            setCategoryId(
                initialCategoryId ||
                    (rule.action_category_id
                        ? String(rule.action_category_id)
                        : ''),
            );
            setLabelIds(rule.labels?.map((label) => label.id) ?? []);
            setErrors({});

            return;
        }

        setTitle(initialTitle);
        setRuleStructure(
            initialRuleStructure ?? {
                groups: [createEmptyGroup()],
                groupOperator: 'or',
            },
        );
        setCategoryId(initialCategoryId);
        setLabelIds([]);
        setErrors({});
    }, [initialCategoryId, initialRuleStructure, initialTitle, mode, rule]);

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

        if (!categoryId && labelIds.length === 0) {
            setErrors((prev) => ({
                ...prev,
                action_category_id:
                    'At least one category or label is required',
            }));
            return;
        }

        setIsSubmitting(true);

        try {
            const jsonLogic = buildJsonLogic(ruleStructure);
            const payload = {
                title: title.trim(),
                priority: rule?.priority ?? 0,
                rules_json: JSON.stringify(jsonLogic),
                action_category_id: categoryId || null,
                action_note: null,
                action_note_iv: null,
                action_label_ids: labelIds,
            };
            const options = {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    setErrors({});
                    onSuccess?.();
                },
                onError: (errors: Record<string, string>) => {
                    setErrors(errors);
                },
                onFinish: () => {
                    setIsSubmitting(false);
                },
            };

            if (mode === 'edit' && rule) {
                router.patch(update(rule.id).url, payload, options);
                return;
            }

            router.post(store().url, payload, options);
        } catch (error) {
            console.error('Failed to submit automation rule:', error);
            setIsSubmitting(false);
        }
    };

    const actionError =
        errors.action_category_id ||
        errors.action_label_ids ||
        errors['action_label_ids.0'];

    return (
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

                <InputError message={errors.title} />
            </div>

            <RuleBuilder
                value={ruleStructure}
                onChange={setRuleStructure}
                error={errors.rules_json}
            />

            <div className="space-y-4 rounded-md border p-4">
                <h4 className="font-medium">{__('Actions')}</h4>

                <div className="space-y-2">
                    <div className="flex items-center justify-between gap-2">
                        <FormLabel htmlFor="category">
                            {__('Set Category')}
                        </FormLabel>
                        {categoryId && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setCategoryId('')}
                            >
                                {__('Clear')}
                            </Button>
                        )}
                    </div>
                    <CategoryCombobox
                        value={categoryId}
                        onValueChange={setCategoryId}
                        categories={categories}
                        placeholder={__('Select a category')}
                        showUncategorized={false}
                        data-testid="action-category-select"
                    />
                </div>

                <div className="space-y-2">
                    <FormLabel>{__('Add Labels')}</FormLabel>
                    <LabelCombobox
                        value={labelIds}
                        onValueChange={setLabelIds}
                        labels={labels}
                        placeholder={__('Select labels')}
                    />
                </div>

                <InputError message={actionError} />
            </div>

            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onCancel}
                    disabled={isSubmitting}
                >
                    {__('Cancel')}
                </Button>
                <Button
                    type="submit"
                    disabled={isSubmitting}
                    data-testid="submit-automation-rule"
                >
                    {isSubmitting
                        ? mode === 'create'
                            ? __('Creating...')
                            : __('Saving...')
                        : mode === 'create'
                          ? __('Create')
                          : __('Save Changes')}
                </Button>
            </div>
        </form>
    );
}
