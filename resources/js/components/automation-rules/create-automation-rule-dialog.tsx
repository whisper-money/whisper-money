import { store } from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
import { RuleBuilder } from '@/components/automation-rules/rule-builder';
import { CategoryCombobox } from '@/components/shared/category-combobox';
import { LabelCombobox } from '@/components/shared/label-combobox';
import { Button } from '@/components/ui/button';
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
import { Textarea } from '@/components/ui/textarea';
import { encrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import {
    buildJsonLogic,
    createEmptyGroup,
    isValidRuleStructure,
    type RuleStructure,
} from '@/lib/rule-builder-utils';
import { automationRuleSyncService } from '@/services/automation-rule-sync';
import { categorySyncService } from '@/services/category-sync';
import { labelSyncService } from '@/services/label-sync';
import type { Category } from '@/types/category';
import type { Label } from '@/types/label';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface CreateAutomationRuleDialogProps {
    disabled?: boolean;
    onSuccess?: () => void;
}

export function CreateAutomationRuleDialog({
    disabled = false,
    onSuccess,
}: CreateAutomationRuleDialogProps) {
    const [open, setOpen] = useState(false);
    const [categories, setCategories] = useState<Category[]>([]);
    const [labels, setLabels] = useState<Label[]>([]);
    const [title, setTitle] = useState('');
    const [priority, setPriority] = useState('10');
    const [ruleStructure, setRuleStructure] = useState<RuleStructure>({
        groups: [createEmptyGroup()],
        groupOperator: 'or',
    });
    const [categoryId, setCategoryId] = useState<string>('');
    const [selectedLabelIds, setSelectedLabelIds] = useState<string[]>([]);
    const [note, setNote] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        const loadData = async () => {
            const [categoriesData, labelsData] = await Promise.all([
                categorySyncService.getAll(),
                labelSyncService.getAll(),
            ]);
            setCategories(categoriesData);
            setLabels(labelsData);
        };
        loadData();
    }, []);

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

        if (!categoryId && !note.trim() && selectedLabelIds.length === 0) {
            setErrors((prev) => ({
                ...prev,
                action_category_id: 'At least one action is required',
            }));
            return;
        }

        setIsSubmitting(true);

        try {
            let encryptedNote: string | null = null;
            let noteIv: string | null = null;

            if (note.trim()) {
                const keyString = getStoredKey();
                if (!keyString) {
                    throw new Error('Encryption key not available');
                }
                const key = await importKey(keyString);
                const encrypted = await encrypt(note.trim(), key);
                encryptedNote = encrypted.encrypted;
                noteIv = encrypted.iv;
            }

            const jsonLogic = buildJsonLogic(ruleStructure);

            router.post(
                store().url,
                {
                    title: title.trim(),
                    priority: parseInt(priority, 10),
                    rules_json: JSON.stringify(jsonLogic),
                    action_category_id: categoryId || null,
                    action_note: encryptedNote,
                    action_note_iv: noteIv,
                    action_label_ids:
                        selectedLabelIds.length > 0 ? selectedLabelIds : null,
                },
                {
                    preserveState: true,
                    preserveScroll: true,
                    onSuccess: async () => {
                        setOpen(false);
                        setTitle('');
                        setPriority('10');
                        setRuleStructure({
                            groups: [createEmptyGroup()],
                            groupOperator: 'and',
                        });
                        setCategoryId('');
                        setSelectedLabelIds([]);
                        setNote('');
                        setErrors({});
                        await automationRuleSyncService.sync();
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
                <Button disabled={disabled}>Create Rule</Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Create Automation Rule</DialogTitle>
                    <DialogDescription>
                        Create a rule to automatically categorize transactions
                        or add notes.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <FormLabel htmlFor="title">Title</FormLabel>
                        <Input
                            id="title"
                            value={title}
                            onChange={(e) => setTitle(e.target.value)}
                            placeholder="Rule title"
                            required
                        />
                        {errors.title && (
                            <p className="text-sm text-red-500">
                                {errors.title}
                            </p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <FormLabel htmlFor="priority">Priority</FormLabel>
                        <Input
                            id="priority"
                            type="number"
                            min="0"
                            value={priority}
                            onChange={(e) => setPriority(e.target.value)}
                            placeholder="10"
                            required
                        />
                        <p className="text-xs text-muted-foreground">
                            Lower numbers execute first
                        </p>
                        {errors.priority && (
                            <p className="text-sm text-red-500">
                                {errors.priority}
                            </p>
                        )}
                    </div>

                    <RuleBuilder
                        value={ruleStructure}
                        onChange={setRuleStructure}
                        error={errors.rules_json}
                    />

                    <div className="space-y-4 rounded-md border p-4">
                        <h4 className="font-medium">Actions</h4>
                        <p className="text-sm text-muted-foreground">
                            At least one action is required
                        </p>

                        <div className="space-y-2">
                            <FormLabel htmlFor="category">
                                Set Category
                            </FormLabel>
                            <CategoryCombobox
                                value={categoryId}
                                onValueChange={setCategoryId}
                                categories={categories}
                                placeholder="Select a category (optional)"
                                showUncategorized={false}
                            />
                        </div>

                        <div className="space-y-2">
                            <FormLabel>Add Labels</FormLabel>
                            <LabelCombobox
                                value={selectedLabelIds}
                                onValueChange={setSelectedLabelIds}
                                labels={labels}
                                onLabelsChange={setLabels}
                                placeholder="Select labels (optional)"
                                allowCreate={true}
                            />
                        </div>

                        <div className="space-y-2">
                            <FormLabel htmlFor="note">Add Note</FormLabel>
                            <Textarea
                                id="note"
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                placeholder="Note to add (optional)"
                                rows={2}
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
                            onClick={() => setOpen(false)}
                            disabled={isSubmitting}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={isSubmitting}>
                            {isSubmitting ? 'Creating...' : 'Create'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
