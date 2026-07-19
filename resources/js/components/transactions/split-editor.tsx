import { LabelCombobox } from '@/components/shared/label-combobox';
import { CategorySelect } from '@/components/transactions/category-select';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/hooks/use-locale';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { __ } from '@/utils/i18n';
import { Plus, X } from 'lucide-react';

export interface SplitLineDraft {
    key: string;
    category_id: string | null;
    amount: number;
    label_ids: string[];
}

interface SplitEditorProps {
    total: number;
    currencyCode: string;
    categories: Category[];
    labels: Label[];
    lines: SplitLineDraft[];
    onChange: (lines: SplitLineDraft[]) => void;
    disabled?: boolean;
    onLabelCreated?: (label: Label) => void;
}

let keyCounter = 0;

export function newSplitLine(
    partial?: Partial<SplitLineDraft>,
): SplitLineDraft {
    keyCounter += 1;

    return {
        key: `split-${keyCounter}`,
        category_id: null,
        amount: 0,
        label_ids: [],
        ...partial,
    };
}

export function splitRemaining(total: number, lines: SplitLineDraft[]): number {
    return total - lines.reduce((sum, line) => sum + line.amount, 0);
}

/**
 * A split is valid once it has at least two lines that each carry a non-zero
 * amount on the same side as the transaction and together reconcile to its
 * total to the cent.
 */
export function isSplitValid(total: number, lines: SplitLineDraft[]): boolean {
    if (lines.length < 2 || splitRemaining(total, lines) !== 0) {
        return false;
    }

    return lines.every(
        (line) =>
            line.amount !== 0 && (total === 0 || line.amount > 0 === total > 0),
    );
}

export function SplitEditor({
    total,
    currencyCode,
    categories,
    labels,
    lines,
    onChange,
    disabled,
    onLabelCreated,
}: SplitEditorProps) {
    const locale = useLocale();
    const remaining = splitRemaining(total, lines);

    const updateLine = (key: string, patch: Partial<SplitLineDraft>) =>
        onChange(
            lines.map((line) =>
                line.key === key ? { ...line, ...patch } : line,
            ),
        );

    const removeLine = (key: string) =>
        onChange(lines.filter((line) => line.key !== key));

    const addLine = () =>
        onChange([...lines, newSplitLine({ amount: remaining })]);

    const formattedRemaining = new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: currencyCode,
    }).format(remaining / 100);

    return (
        <div className="space-y-3" data-testid="split-editor">
            {lines.map((line) => (
                <div
                    key={line.key}
                    className="space-y-2 rounded-md border border-border p-3"
                >
                    <div className="flex items-start gap-2">
                        <div className="flex-1">
                            <CategorySelect
                                value={line.category_id ?? 'null'}
                                onValueChange={(value) =>
                                    updateLine(line.key, {
                                        category_id:
                                            value === 'null' ||
                                            value === 'uncategorized'
                                                ? null
                                                : value,
                                    })
                                }
                                categories={categories}
                                disabled={disabled}
                                showUncategorized={true}
                                triggerClassName="w-full"
                            />
                        </div>
                        <div className="w-32">
                            <AmountInput
                                value={line.amount}
                                onChange={(value) =>
                                    updateLine(line.key, { amount: value })
                                }
                                currencyCode={currencyCode}
                                disabled={disabled}
                                allowNegative={true}
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            onClick={() => removeLine(line.key)}
                            disabled={disabled || lines.length <= 2}
                            aria-label={__('Remove line')}
                        >
                            <X className="h-4 w-4" />
                        </Button>
                    </div>
                    <LabelCombobox
                        value={line.label_ids}
                        onValueChange={(ids) =>
                            updateLine(line.key, { label_ids: ids })
                        }
                        labels={labels}
                        disabled={disabled}
                        placeholder={__('Add labels...')}
                        allowCreate={true}
                        onLabelCreated={onLabelCreated}
                    />
                </div>
            ))}

            <div className="flex items-center justify-between">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={addLine}
                    disabled={disabled}
                >
                    <Plus className="mr-1 h-4 w-4" />
                    {__('Add line')}
                </Button>
                <span
                    className={
                        remaining === 0
                            ? 'text-sm text-muted-foreground'
                            : 'text-sm font-medium text-destructive'
                    }
                    data-testid="split-remaining"
                >
                    {__('Remaining: :amount', { amount: formattedRemaining })}
                </span>
            </div>
        </div>
    );
}
