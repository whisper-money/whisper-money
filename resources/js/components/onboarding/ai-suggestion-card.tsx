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
import { Loader2, Receipt, Sparkles } from 'lucide-react';
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
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [previewData, setPreviewData] = useState<PreviewResponse | null>(
        null,
    );
    const [fetchedToken, setFetchedToken] = useState<string | null>(null);

    const openPreview = async () => {
        setOpen(true);

        // Refetch when the token changed since the last load.
        if (previewData && fetchedToken === draft.token) {
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
            setFetchedToken(draft.token);
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
                    className="mt-1 shrink-0"
                    aria-label={__('Include this rule')}
                />

                <div className="min-w-0 flex-1 space-y-3">
                    {/* Match: field + operator + token */}
                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                        <div className="flex items-center gap-2">
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
                        </div>
                        <Input
                            value={draft.token}
                            onChange={(event) =>
                                onChange({
                                    ...draft,
                                    token: event.target.value,
                                })
                            }
                            className="h-9 w-full sm:w-48"
                            aria-label={__('Match text')}
                        />
                    </div>

                    {/* Category */}
                    <div className="flex flex-col gap-1">
                        <Label className="text-xs text-muted-foreground">
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

                    {/* Preview button (carries the match count) */}
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={openPreview}
                        className="w-full gap-2 sm:w-auto"
                    >
                        <Receipt className="size-4" />
                        {__('Preview :count matching transactions', {
                            count: suggestion.group_size,
                        })}
                    </Button>
                </div>
            </div>

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
