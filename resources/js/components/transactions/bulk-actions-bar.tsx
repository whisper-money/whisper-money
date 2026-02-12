import { BulkCategorySelect } from '@/components/transactions/bulk-category-select';
import { BulkLabelSelect } from '@/components/transactions/bulk-label-select';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { __ } from '@/utils/i18n';
import {
    CheckCheck,
    MoreHorizontal,
    Trash2,
    WandSparkles,
    X,
} from 'lucide-react';

interface BulkActionsBarProps {
    selectedCount: number;
    totalFilteredCount?: number;
    isSelectingAll?: boolean;
    categories: Category[];
    labels: Label[];
    onCategoryChange: (categoryId: number | null) => void;
    onLabelsChange: (labelIds: string[]) => void;
    onDelete: () => void;
    onReEvaluateRules: () => void;
    onSelectAll?: () => void;
    onClear: () => void;
    isUpdating?: boolean;
}

export function BulkActionsBar({
    selectedCount,
    totalFilteredCount,
    isSelectingAll = false,
    categories,
    labels,
    onCategoryChange,
    onLabelsChange,
    onDelete,
    onReEvaluateRules,
    onSelectAll,
    onClear,
    isUpdating = false,
}: BulkActionsBarProps) {
    if (selectedCount < 1) {
        return null;
    }

    const displayCount = isSelectingAll ? totalFilteredCount : selectedCount;
    const canSelectAll =
        !isSelectingAll &&
        totalFilteredCount &&
        selectedCount < totalFilteredCount &&
        onSelectAll;

    return (
        <div className="fixed bottom-6 flex w-full animate-in items-center justify-center duration-300 slide-in-from-bottom-5 slide-out-to-bottom-5 fade-in fade-out">
            <div className="flex max-w-[75%] flex-row items-center justify-between gap-10 rounded-full border border-border bg-card px-4 py-2 shadow-lg">
                <div className="flex items-center gap-2 pl-2 text-sm">
                    {isSelectingAll ? (
                        <>
                            {__('All')}
                            {displayCount} transaction
                            {displayCount !== 1 ? 's' : ''} selected
                        </>
                    ) : (
                        <>
                            {displayCount} transaction
                            {displayCount !== 1 ? 's' : ''} selected
                        </>
                    )}
                    {canSelectAll && (
                        <>
                            <TooltipProvider>
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={onSelectAll}
                                            disabled={isUpdating}
                                            className="h-auto px-0 py-1 text-xs text-primary hover:text-primary/80"
                                        >
                                            <CheckCheck className="mr-1 h-3 w-3" />
                                            {__('Select all')}
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        {__('Select all')}
                                        {totalFilteredCount}{' '}
                                        {__(
                                            'transactions matching current filter',
                                        )}
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </>
                    )}
                </div>

                <ButtonGroup>
                    <ButtonGroup>
                        <BulkCategorySelect
                            categories={categories}
                            onCategoryChange={onCategoryChange}
                            disabled={isUpdating}
                        />

                        <BulkLabelSelect
                            labels={labels}
                            onLabelsChange={onLabelsChange}
                            disabled={isUpdating}
                        />

                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    disabled={isUpdating}
                                    aria-label={__('More actions')}
                                >
                                    <MoreHorizontal className="h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuGroup>
                                    <DropdownMenuItem
                                        onClick={onReEvaluateRules}
                                        disabled={isUpdating}
                                    >
                                        <WandSparkles className="h-4 w-4" />
                                        {__('Re-evaluate rules')}
                                    </DropdownMenuItem>

                                    <DropdownMenuItem
                                        variant="destructive"
                                        onSelect={onDelete}
                                    >
                                        <Trash2 />
                                        {__('Delete')}
                                    </DropdownMenuItem>
                                </DropdownMenuGroup>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </ButtonGroup>

                    <ButtonGroup>
                        <Button
                            variant="ghost"
                            size="icon"
                            onClick={onClear}
                            disabled={isUpdating}
                            aria-label={__('Clear selection')}
                        >
                            <X className="h-4 w-4" />
                        </Button>
                    </ButtonGroup>
                </ButtonGroup>
            </div>
        </div>
    );
}
