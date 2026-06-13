import { CategoryCombobox } from '@/components/shared/category-combobox';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { preview } from '@/routes/ai/rule-suggestions';
import { type Category } from '@/types/category';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import axios from 'axios';
import {
    ChevronDown,
    Loader2,
    Plus,
    Sparkles,
    TextSearch,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

export interface AiSuggestionValue {
    id: string;
    match_field: string;
    match_operator: string;
    match_token: string;
}

export interface AiSuggestion {
    id: string;
    confidence: number;
    group_size: number;
    sample_descriptions: string[];
    proposed_category: { id: string; name: string } | null;
    new_category_name: string | null;
    new_category_direction: string | null;
    values: AiSuggestionValue[];
}

export interface ValueDraft {
    field: string;
    operator: string;
    token: string;
}

export interface SuggestionDraft {
    include: boolean;
    categoryId: string | null;
    values: ValueDraft[];
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

function operatorLabel(operator: string): string {
    return operator === 'equals' ? __('is') : __('contains');
}

function conditionsFor(values: ValueDraft[]) {
    return values
        .filter((value) => value.token.trim() !== '')
        .map((value) => ({
            match_field: value.field,
            match_operator: value.operator,
            match_token: value.token.trim(),
        }));
}

export function AiSuggestionCard({
    suggestion,
    draft,
    categories,
    onChange,
}: AiSuggestionCardProps) {
    const [expanded, setExpanded] = useState(false);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [previewData, setPreviewData] = useState<PreviewResponse | null>(
        null,
    );

    const conditions = useMemo(
        () => conditionsFor(draft.values),
        [draft.values],
    );
    const conditionsKey = useMemo(
        () => JSON.stringify(conditions),
        [conditions],
    );

    // The server already counted the original values; only refetch once the
    // user has edited them, so an untouched card costs no request.
    const initialKey = useRef(conditionsKey).current;

    useEffect(() => {
        if (conditionsKey === initialKey) {
            return;
        }

        if (conditions.length === 0) {
            setPreviewData((current) => ({
                match_count: 0,
                total_uncategorized: current?.total_uncategorized ?? 0,
                transactions: [],
            }));
            return;
        }

        const handle = setTimeout(async () => {
            setLoading(true);
            try {
                const { data } = await axios.post<PreviewResponse>(
                    preview().url,
                    { conditions },
                );
                setPreviewData(data);
            } finally {
                setLoading(false);
            }
        }, 400);

        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [conditionsKey]);

    const matchCount = previewData?.match_count ?? suggestion.group_size;

    const selectedCategory = categories.find((c) => c.id === draft.categoryId);
    const categoryLabel =
        selectedCategory?.name ??
        (suggestion.new_category_name
            ? __('New: :name', { name: suggestion.new_category_name })
            : '—');

    const valuesSummary = draft.values
        .filter((value) => value.token.trim() !== '')
        .map((value) => `“${value.token}”`)
        .join(` ${__('or')} `);

    const updateValue = (index: number, patch: Partial<ValueDraft>) => {
        onChange({
            ...draft,
            values: draft.values.map((value, i) =>
                i === index ? { ...value, ...patch } : value,
            ),
        });
    };

    const removeValue = (index: number) => {
        onChange({
            ...draft,
            values: draft.values.filter((_, i) => i !== index),
        });
    };

    const addValue = () => {
        onChange({
            ...draft,
            values: [
                ...draft.values,
                { field: 'description', operator: 'contains', token: '' },
            ],
        });
    };

    const openPreview = async () => {
        setOpen(true);

        if (previewData || conditions.length === 0) {
            return;
        }

        setLoading(true);
        try {
            const { data } = await axios.post<PreviewResponse>(preview().url, {
                conditions,
            });
            setPreviewData(data);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="overflow-hidden rounded-xl border bg-card">
            {/* Collapsed header: select + summary + expand toggle */}
            <div className="flex items-center gap-3 p-3">
                <Checkbox
                    checked={draft.include}
                    onCheckedChange={(checked) =>
                        onChange({ ...draft, include: checked === true })
                    }
                    aria-label={__('Include this rule')}
                    className="shrink-0"
                />
                <button
                    type="button"
                    onClick={() => setExpanded((value) => !value)}
                    aria-expanded={expanded}
                    className="flex min-w-0 flex-1 items-center gap-2 text-left"
                >
                    <span className="min-w-0 flex-1 truncate text-sm">
                        <span className="font-medium">{valuesSummary}</span>
                        <span className="text-muted-foreground"> → </span>
                        <span>{categoryLabel}</span>
                    </span>
                    <span className="shrink-0 text-xs text-muted-foreground">
                        {__(':count matches', { count: matchCount })}
                    </span>
                    <ChevronDown
                        className={`size-4 shrink-0 text-muted-foreground transition-transform ${expanded ? 'rotate-180' : ''}`}
                    />
                </button>
            </div>

            {/* Expanded body: edit values, category, preview */}
            {expanded && (
                <div className="space-y-3 border-t p-4">
                    <Label className="text-xs text-muted-foreground">
                        {__('If the transaction matches any of')}
                    </Label>

                    <div className="flex flex-col gap-2 pt-1">
                        {draft.values.map((value, index) => (
                            <div
                                key={index}
                                className="flex flex-col gap-2 sm:flex-row sm:items-center"
                            >
                                <Input
                                    value={__(
                                        FIELD_LABELS[value.field] ??
                                            'Description',
                                    )}
                                    className="h-9 w-full"
                                    disabled
                                />
                                <Input
                                    value={operatorLabel(value.operator)}
                                    className="h-9 w-full"
                                    disabled
                                />
                                <Input
                                    value={value.token}
                                    onChange={(event) =>
                                        updateValue(index, {
                                            token: event.target.value,
                                        })
                                    }
                                    className="h-9 w-full"
                                    aria-label={__('Match text')}
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    onClick={() => removeValue(index)}
                                    disabled={draft.values.length === 1}
                                    aria-label={__('Remove value')}
                                    className="size-9 shrink-0"
                                >
                                    <X className="size-4" />
                                </Button>
                            </div>
                        ))}
                    </div>

                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={addValue}
                        className="gap-2"
                    >
                        <Plus className="size-4 shrink-0" />
                        {__('Add value')}
                    </Button>

                    <div className="flex flex-col gap-1">
                        <Label className="pb-1 text-xs text-muted-foreground">
                            {__('Categorize as')}
                        </Label>
                        <div className="flex flex-wrap items-center gap-2">
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

                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={openPreview}
                        className="w-full justify-center gap-2"
                    >
                        <TextSearch className="size-4 shrink-0" />
                        <span className="truncate">
                            {__('Preview :count matching transactions', {
                                count: matchCount,
                            })}
                        </span>
                    </Button>
                </div>
            )}

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[85vh] gap-0 overflow-hidden p-0 sm:max-w-2xl">
                    <DialogHeader className="space-y-1 p-6 pb-4">
                        <DialogTitle>{__('Matching transactions')}</DialogTitle>
                        <DialogDescription>
                            {previewData
                                ? __(
                                      ':count of :total uncategorized transactions match',
                                      {
                                          count: previewData.match_count,
                                          total: previewData.total_uncategorized,
                                      },
                                  )
                                : __('Loading…')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="max-h-[60vh] overflow-y-auto border-t">
                        {loading ? (
                            <div className="flex items-center justify-center gap-2 p-8 text-sm text-muted-foreground">
                                <Loader2 className="size-4 animate-spin" />
                                {__('Loading…')}
                            </div>
                        ) : (
                            <Table>
                                <TableHeader className="sticky top-0 bg-background">
                                    <TableRow>
                                        <TableHead>{__('Date')}</TableHead>
                                        <TableHead>
                                            {__('Description')}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {__('Amount')}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {previewData?.transactions.map(
                                        (transaction) => (
                                            <TableRow key={transaction.id}>
                                                <TableCell className="whitespace-nowrap text-muted-foreground">
                                                    {formatDate(
                                                        transaction.transaction_date,
                                                        'd MMM yyyy',
                                                    )}
                                                </TableCell>
                                                <TableCell className="max-w-[18rem] truncate">
                                                    {transaction.description ??
                                                        '—'}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <AmountDisplay
                                                        amountInCents={
                                                            transaction.amount
                                                        }
                                                        currencyCode={
                                                            transaction.currency_code
                                                        }
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        ),
                                    )}
                                </TableBody>
                            </Table>
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    );
}
