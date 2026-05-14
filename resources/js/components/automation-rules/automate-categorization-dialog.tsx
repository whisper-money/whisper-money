import { AutomationRuleForm } from '@/components/automation-rules/automation-rule-form';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    addDescriptionMatchToRuleStructure,
    createDescriptionCondition,
    parseJsonLogic,
    type RuleStructure,
} from '@/lib/rule-builder-utils';
import type { AutomationRule } from '@/types/automation-rule';
import type { Category } from '@/types/category';
import type { Label } from '@/types/label';
import type { DecryptedTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { ArrowLeft, FilePlus2, ListPlus } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

export interface AutomateCategorizationCandidate {
    transaction: DecryptedTransaction;
    category: Category;
}

interface AutomateCategorizationDialogProps {
    open: boolean;
    candidate: AutomateCategorizationCandidate | null;
    categories: Category[];
    onOpenChange: (open: boolean) => void;
    onSaved?: () => void;
}

type Step = 'choose' | 'create' | 'select-rule' | 'edit';

function descriptionRuleStructure(description: string): RuleStructure {
    return {
        groups: [
            {
                id: crypto.randomUUID(),
                operator: 'or',
                conditions: [createDescriptionCondition(description)],
            },
        ],
        groupOperator: 'or',
    };
}

export function AutomateCategorizationDialog({
    open,
    candidate,
    categories,
    onOpenChange,
    onSaved,
}: AutomateCategorizationDialogProps) {
    const { automationRules: rawRules, labels } = usePage<{
        automationRules: AutomationRule[];
        labels: Label[];
    }>().props;
    const rules = useMemo(
        () =>
            rawRules.map((rule) => ({
                ...rule,
                rules_json:
                    typeof rule.rules_json === 'string'
                        ? JSON.parse(rule.rules_json)
                        : rule.rules_json,
            })),
        [rawRules],
    );
    const [step, setStep] = useState<Step>('choose');
    const [selectedRule, setSelectedRule] = useState<AutomationRule | null>(
        null,
    );

    useEffect(() => {
        if (open) {
            setStep('choose');
            setSelectedRule(null);
        }
    }, [open, candidate]);

    if (!candidate) {
        return null;
    }

    const description =
        candidate.transaction.decryptedDescription ||
        candidate.transaction.description;
    const createInitialStructure = descriptionRuleStructure(description);
    const editInitialStructure = selectedRule
        ? addDescriptionMatchToRuleStructure(
              parseJsonLogic(selectedRule.rules_json),
              description,
          )
        : undefined;

    const handleSaved = () => {
        onOpenChange(false);
        onSaved?.();
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="overflow-x-hidden sm:max-w-[640px]">
                <DialogHeader>
                    <DialogTitle>
                        {step === 'create'
                            ? __('Create Automation Rule')
                            : step === 'edit'
                              ? __('Edit Automation Rule')
                              : __('Automatize Categorization')}
                    </DialogTitle>
                    <DialogDescription>
                        {__(
                            'Create or update a rule so future transactions with this description are categorized automatically.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <div className="rounded border-l-primary/60 bg-muted/40 px-4 py-3 text-sm">
                    <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        {__('Transaction')}
                    </div>
                    <div className="mt-1 font-medium text-foreground">
                        {candidate.category.name}
                    </div>
                    <div className="mt-1 line-clamp-2 text-muted-foreground">
                        {description}
                    </div>
                </div>

                {step === 'choose' && (
                    <div className="grid gap-3">
                        <h4 className="text-sm font-medium opacity-75">
                            {__('What do you want to do?')}
                        </h4>
                        <Button
                            type="button"
                            variant="outline"
                            className="h-auto items-start justify-start gap-3 p-4 text-left whitespace-normal"
                            onClick={() => setStep('create')}
                        >
                            <FilePlus2 className="mt-0.5 h-5 w-5" />
                            <span className="min-w-0 flex-1 whitespace-normal">
                                <span className="block font-medium whitespace-normal">
                                    {__('Create a new rule')}
                                </span>
                                <span className="mt-1 block text-sm leading-5 break-words whitespace-normal text-muted-foreground">
                                    {__(
                                        'Start with this category and transaction description.',
                                    )}
                                </span>
                            </span>
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            className="h-auto items-start justify-start gap-3 p-4 text-left whitespace-normal"
                            onClick={() => setStep('select-rule')}
                            disabled={rules.length === 0}
                        >
                            <ListPlus className="mt-0.5 h-5 w-5" />
                            <span className="min-w-0 flex-1 whitespace-normal">
                                <span className="block font-medium whitespace-normal">
                                    {__('Modify an existing rule')}
                                </span>
                                <span className="mt-1 block text-sm leading-5 break-words whitespace-normal text-muted-foreground">
                                    {rules.length === 0
                                        ? __('No automation rules available.')
                                        : __('Add this description to a rule.')}
                                </span>
                            </span>
                        </Button>
                    </div>
                )}

                {step === 'select-rule' && (
                    <div className="space-y-3">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="gap-2"
                            onClick={() => setStep('choose')}
                        >
                            <ArrowLeft className="h-4 w-4" />
                            {__('Back')}
                        </Button>
                        <div className="max-h-80 space-y-2 overflow-y-auto">
                            {rules.map((rule) => (
                                <Button
                                    key={rule.id}
                                    type="button"
                                    variant="outline"
                                    className="h-auto w-full justify-start p-3 text-left"
                                    onClick={() => {
                                        setSelectedRule(rule);
                                        setStep('edit');
                                    }}
                                >
                                    <span>
                                        <span className="block font-medium">
                                            {rule.title}
                                        </span>
                                        <span className="block text-sm text-muted-foreground">
                                            {rule.category?.name ??
                                                __('No category set')}
                                        </span>
                                    </span>
                                </Button>
                            ))}
                        </div>
                    </div>
                )}

                {step === 'create' && (
                    <AutomationRuleForm
                        mode="create"
                        categories={categories}
                        labels={labels}
                        initialTitle={candidate.category.name}
                        initialRuleStructure={createInitialStructure}
                        initialCategoryId={String(candidate.category.id)}
                        onCancel={() => setStep('choose')}
                        onSuccess={handleSaved}
                    />
                )}

                {step === 'edit' && selectedRule && editInitialStructure && (
                    <AutomationRuleForm
                        key={`${selectedRule.id}-${description}`}
                        mode="edit"
                        rule={selectedRule}
                        categories={categories}
                        labels={labels}
                        initialRuleStructure={editInitialStructure}
                        onCancel={() => setStep('select-rule')}
                        onSuccess={handleSaved}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
