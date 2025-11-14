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
import { Textarea } from '@/components/ui/textarea';
import { decrypt, encrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import {
    buildJsonLogic,
    createEmptyGroup,
    isValidRuleStructure,
    parseJsonLogic,
    type RuleStructure,
} from '@/lib/rule-builder-utils';
import { automationRuleSyncService } from '@/services/automation-rule-sync';
import { categorySyncService } from '@/services/category-sync';
import type { AutomationRule } from '@/types/automation-rule';
import type { Category } from '@/types/category';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface EditAutomationRuleDialogProps {
    rule: AutomationRule;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess?: () => void;
}

export function EditAutomationRuleDialog({
    rule,
    open,
    onOpenChange,
    onSuccess,
}: EditAutomationRuleDialogProps) {
    const [categories, setCategories] = useState<Category[]>([]);
    const [title, setTitle] = useState('');
    const [priority, setPriority] = useState('0');
    const [ruleStructure, setRuleStructure] = useState<RuleStructure>({
        groups: [createEmptyGroup()],
        groupOperator: 'and',
    });
    const [categoryId, setCategoryId] = useState<string>('');
    const [note, setNote] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        const loadCategories = async () => {
            const data = await categorySyncService.getAll();
            setCategories(data);
        };
        loadCategories();
    }, []);

    useEffect(() => {
        if (rule && open) {
            setTitle(rule.title);
            setPriority(String(rule.priority));
            setRuleStructure(parseJsonLogic(rule.rules_json));
            setCategoryId(
                rule.action_category_id ? String(rule.action_category_id) : '',
            );

            const decryptNote = async () => {
                if (rule.action_note && rule.action_note_iv) {
                    try {
                        const keyString = getStoredKey();
                        if (keyString) {
                            const key = await importKey(keyString);
                            const decrypted = await decrypt(
                                rule.action_note,
                                key,
                                rule.action_note_iv,
                            );
                            setNote(decrypted);
                        }
                    } catch (error) {
                        console.error('Failed to decrypt note:', error);
                    }
                } else {
                    setNote('');
                }
            };

            decryptNote();
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

        if (!categoryId && !note.trim()) {
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

            router.patch(
                update(rule.id).url,
                {
                    title: title.trim(),
                    priority: parseInt(priority, 10),
                    rules_json: JSON.stringify(jsonLogic),
                    action_category_id: categoryId
                        ? parseInt(categoryId, 10)
                        : null,
                    action_note: encryptedNote,
                    action_note_iv: noteIv,
                },
                {
                    onSuccess: async () => {
                        onOpenChange(false);
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
            console.error('Failed to update automation rule:', error);
            setIsSubmitting(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Edit Automation Rule</DialogTitle>
                    <DialogDescription>
                        Update the rule to automatically categorize transactions
                        or add notes.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="title">Title</Label>
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
                        <Label htmlFor="priority">Priority</Label>
                        <Input
                            id="priority"
                            type="number"
                            min="0"
                            value={priority}
                            onChange={(e) => setPriority(e.target.value)}
                            placeholder="0"
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
                            <Label htmlFor="category">Set Category</Label>
                            <CategoryCombobox
                                value={categoryId}
                                onValueChange={setCategoryId}
                                categories={categories}
                                placeholder="Select a category (optional)"
                                showUncategorized={false}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="note">Add Note</Label>
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
                            onClick={() => onOpenChange(false)}
                            disabled={isSubmitting}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={isSubmitting}>
                            {isSubmitting ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
