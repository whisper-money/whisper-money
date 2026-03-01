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

    return (
        <div className="fixed right-0 bottom-8 left-0 flex w-full animate-in items-center justify-center duration-300 slide-in-from-bottom-5 slide-out-to-bottom-5 fade-in fade-out">
            <div className="flex max-w-[75%] flex-row items-center justify-between gap-6 rounded-full border border-border bg-sidebar px-4 py-2 shadow-lg">
                <div className="flex items-center gap-2 pl-2 text-sm">
                    {isSelectingAll ? (
                        <span className="flex items-center gap-2 text-primary">
                            <CheckCheck className="h-4 w-4" />
                            {__('All matching filters')}
                        </span>
                    ) : (
                        <>
                            <span className="whitespace-nowrap">
                                {selectedCount !== 1
                                    ? __(`:count transactions`, {
                                          count: selectedCount,
                                      })
                                    : __(`:count transaction`, {
                                          count: selectedCount,
                                      })}
                            </span>
                            {onSelectAll && (
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            onClick={onSelectAll}
                                            disabled={isUpdating}
                                            className="h-6 w-6 text-primary hover:text-primary/80"
                                        >
                                            <CheckCheck className="h-3 w-3" />
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        {__(
                                            'Select all transactions matching filter',
                                        )}
                                    </TooltipContent>
                                </Tooltip>
                            )}
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

                                    {!isSelectingAll && (
                                        <DropdownMenuItem
                                            variant="destructive"
                                            onSelect={onDelete}
                                        >
                                            <Trash2 />
                                            {__('Delete')}
                                        </DropdownMenuItem>
                                    )}
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
