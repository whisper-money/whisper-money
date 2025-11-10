import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { ChevronDown } from 'lucide-react';
import { update } from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
import { categorySyncService } from '@/services/category-sync';
import { encrypt, decrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import type { Category } from '@/types/category';
import type { AutomationRule } from '@/types/automation-rule';

interface EditAutomationRuleDialogProps {
    rule: AutomationRule;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function EditAutomationRuleDialog({
    rule,
    open,
    onOpenChange,
}: EditAutomationRuleDialogProps) {
    const [categories, setCategories] = useState<Category[]>([]);
    const [title, setTitle] = useState('');
    const [priority, setPriority] = useState('0');
    const [rulesJson, setRulesJson] = useState('');
    const [categoryId, setCategoryId] = useState<string>('');
    const [note, setNote] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [helpOpen, setHelpOpen] = useState(false);

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
            setRulesJson(JSON.stringify(rule.rules_json, null, 2));
            setCategoryId(rule.action_category_id ? String(rule.action_category_id) : '');

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

    const validateJsonLogic = (json: string): boolean => {
        try {
            const parsed = JSON.parse(json);
            if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
                return false;
            }
            return Object.keys(parsed).length > 0;
        } catch {
            return false;
        }
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setErrors({});

        if (!title.trim()) {
            setErrors((prev) => ({ ...prev, title: 'Title is required' }));
            return;
        }

        if (!rulesJson.trim()) {
            setErrors((prev) => ({ ...prev, rules_json: 'Rules JSON is required' }));
            return;
        }

        if (!validateJsonLogic(rulesJson)) {
            setErrors((prev) => ({
                ...prev,
                rules_json: 'Invalid JsonLogic format',
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

            router.patch(
                update(rule.id).url,
                {
                    title: title.trim(),
                    priority: parseInt(priority, 10),
                    rules_json: rulesJson.trim(),
                    action_category_id: categoryId ? parseInt(categoryId, 10) : null,
                    action_note: encryptedNote,
                    action_note_iv: noteIv,
                },
                {
                    onSuccess: () => {
                        onOpenChange(false);
                        setErrors({});
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
                        Update the rule to automatically categorize transactions or add notes.
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
                            <p className="text-sm text-red-500">{errors.title}</p>
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
                        <p className="text-muted-foreground text-xs">
                            Lower numbers execute first
                        </p>
                        {errors.priority && (
                            <p className="text-sm text-red-500">{errors.priority}</p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="rules_json">Rules (JsonLogic)</Label>
                        <Textarea
                            id="rules_json"
                            value={rulesJson}
                            onChange={(e) => setRulesJson(e.target.value)}
                            placeholder='{"==": [{"var": "description"}, "GROCERY"]}'
                            rows={4}
                            className="font-mono text-sm"
                            required
                        />
                        {errors.rules_json && (
                            <p className="text-sm text-red-500">{errors.rules_json}</p>
                        )}
                    </div>

                    <Collapsible open={helpOpen} onOpenChange={setHelpOpen}>
                        <CollapsibleTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="flex w-full items-center justify-between"
                            >
                                <span>Available Fields & Examples</span>
                                <ChevronDown
                                    className={`h-4 w-4 transition-transform ${helpOpen ? 'rotate-180' : ''}`}
                                />
                            </Button>
                        </CollapsibleTrigger>
                        <CollapsibleContent className="space-y-2 rounded-md border p-3 text-sm">
                            <div>
                                <strong>Available fields:</strong>
                                <ul className="ml-4 mt-1 list-disc">
                                    <li>description (string)</li>
                                    <li>amount (number)</li>
                                    <li>transaction_date (string)</li>
                                    <li>bank_name (string)</li>
                                    <li>account_name (string)</li>
                                    <li>category (string or null)</li>
                                </ul>
                            </div>
                            <div>
                                <strong>Example rules:</strong>
                                <pre className="bg-muted mt-1 overflow-x-auto rounded p-2 text-xs">
{`{"in": ["GROCERY", {"var": "description"}]}
{"and": [
  {">": [{"var": "amount"}, 100]},
  {"==": [{"var": "bank_name"}, "Chase"]}
]}
{"==": [{"var": "category"}, null]}`}
                                </pre>
                            </div>
                        </CollapsibleContent>
                    </Collapsible>

                    <div className="space-y-4 rounded-md border p-4">
                        <h4 className="font-medium">Actions</h4>
                        <p className="text-muted-foreground text-sm">
                            At least one action is required
                        </p>

                        <div className="space-y-2">
                            <Label htmlFor="category">Set Category</Label>
                            <Select value={categoryId} onValueChange={setCategoryId}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a category (optional)" />
                                </SelectTrigger>
                                <SelectContent>
                                    {categories.map((category) => (
                                        <SelectItem
                                            key={category.id}
                                            value={String(category.id)}
                                        >
                                            {category.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
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

