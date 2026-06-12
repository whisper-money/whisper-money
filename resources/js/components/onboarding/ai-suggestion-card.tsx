import { CategoryCombobox } from '@/components/shared/category-combobox';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { preview } from '@/routes/ai/rule-suggestions';
import { type Category } from '@/types/category';
import { __ } from '@/utils/i18n';
import axios from 'axios';
import { ChevronDown, Loader2, Sparkles } from 'lucide-react';
import { useState } from 'react';

export interface AiSuggestion {
    id: string;
    match_field: string;
    match_operator: string;
    match_token: string;
    confidence: number;
    group_size: number;
    sample_descriptions: string[];
    proposed_category: { id: string; name: string } | null;
    new_category_name: string | null;
    new_category_direction: string | null;
}

export interface SuggestionDraft {
    include: boolean;
    token: string;
    categoryId: string | null;
}

interface PreviewTransaction {
    id: string;
    description: string | null;
    amount: number;
    currency_code: string;
    transaction_date: string;
}

interface PreviewResponse {
    match_count: number;
    total_uncategorized: number;
    transactions: PreviewTransaction[];
}

interface AiSuggestionCardProps {
    suggestion: AiSuggestion;
    draft: SuggestionDraft;
    categories: Category[];
    onChange: (draft: SuggestionDraft) => void;
}

const FIELD_LABELS: Record<string, string> = {
    description: 'Description',
    creditor_name: 'Payee',
    debtor_name: 'Sender',
};

export function AiSuggestionCard({
    suggestion,
    draft,
    categories,
    onChange,
}: AiSuggestionCardProps) {
    const [expanded, setExpanded] = useState(false);
    const [loading, setLoading] = useState(false);
    const [previewData, setPreviewData] = useState<PreviewResponse | null>(
        null,
    );

    const loadPreview = async () => {
        if (expanded) {
            setExpanded(false);
            return;
        }

        setExpanded(true);

        if (previewData) {
            return;
        }

        setLoading(true);
        try {
            const query = new URLSearchParams({
                match_field: suggestion.match_field,
                match_operator: suggestion.match_operator,
                match_token: draft.token,
            });
            const { data } = await axios.get<PreviewResponse>(
                `${preview().url}?${query.toString()}`,
            );
            setPreviewData(data);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="rounded-xl border bg-card p-4">
            <div className="flex items-start gap-3">
                <Checkbox
                    checked={draft.include}
                    onCheckedChange={(checked) =>
                        onChange({ ...draft, include: checked === true })
                    }
                    className="mt-1"
                    aria-label={__('Include this rule')}
                />

                <div className="flex-1 space-y-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="secondary">
                            {__(
                                FIELD_LABELS[suggestion.match_field] ??
                                    'Description',
                            )}
                        </Badge>
                        <span className="text-sm text-muted-foreground">
                            {suggestion.match_operator === 'equals'
                                ? __('is')
                                : __('contains')}
                        </span>
                        <Input
                            value={draft.token}
                            onChange={(event) => {
                                setPreviewData(null);
                                onChange({
                                    ...draft,
                                    token: event.target.value,
                                });
                            }}
                            className="h-8 w-40"
                            aria-label={__('Match text')}
                        />
                        <Badge variant="outline">
                            {__(':count transactions', {
                                count: suggestion.group_size,
                            })}
                        </Badge>
                    </div>

                    <div className="flex flex-col gap-1">
                        <Label className="text-xs text-muted-foreground">
                            {__('Categorize as')}
                        </Label>
                        <div className="flex items-center gap-2">
                            <CategoryCombobox
                                value={draft.categoryId}
                                onValueChange={(value) =>
                                    onChange({ ...draft, categoryId: value })
                                }
                                categories={categories}
                            />
                            {!draft.categoryId &&
                                suggestion.new_category_name && (
                                    <Badge className="gap-1 bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300">
                                        <Sparkles className="size-3" />
                                        {__('New: :name', {
                                            name: suggestion.new_category_name,
                                        })}
                                    </Badge>
                                )}
                        </div>
                    </div>

                    <button
                        type="button"
                        onClick={loadPreview}
                        className="flex items-center gap-1 text-xs font-medium text-violet-600 hover:underline dark:text-violet-400"
                    >
                        <ChevronDown
                            className={`size-3 transition-transform ${expanded ? 'rotate-180' : ''}`}
                        />
                        {__('Preview matching transactions')}
                    </button>

                    {expanded && (
                        <div className="rounded-lg bg-muted/50 p-3">
                            {loading ? (
                                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <Loader2 className="size-4 animate-spin" />
                                    {__('Loading…')}
                                </div>
                            ) : previewData ? (
                                <div className="space-y-2">
                                    <p className="text-xs text-muted-foreground">
                                        {__(
                                            ':count of :total uncategorized transactions match',
                                            {
                                                count: previewData.match_count,
                                                total: previewData.total_uncategorized,
                                            },
                                        )}
                                    </p>
                                    <ul className="space-y-1">
                                        {previewData.transactions
                                            .slice(0, 5)
                                            .map((transaction) => (
                                                <li
                                                    key={transaction.id}
                                                    className="flex items-center justify-between gap-2 text-sm"
                                                >
                                                    <span className="truncate text-foreground">
                                                        {transaction.description ??
                                                            '—'}
                                                    </span>
                                                    <AmountDisplay
                                                        amountInCents={
                                                            transaction.amount
                                                        }
                                                        currencyCode={
                                                            transaction.currency_code
                                                        }
                                                    />
                                                </li>
                                            ))}
                                    </ul>
                                </div>
                            ) : null}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
