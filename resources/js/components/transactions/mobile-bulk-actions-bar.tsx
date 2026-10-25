import { type BulkActionsBarProps } from '@/components/transactions/bulk-actions-bar';
import { BulkCategorySelect } from '@/components/transactions/bulk-category-select';
import { BulkLabelSelect } from '@/components/transactions/bulk-label-select';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { type MobileSelectionMode } from '@/hooks/use-mobile-selection-mode';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import {
    CheckCheck,
    Filter,
    LayoutGrid,
    ListChecks,
    MoreHorizontal,
    Tag,
    Trash2,
    WandSparkles,
    X,
} from 'lucide-react';

interface MobileBulkActionsBarProps extends Omit<
    BulkActionsBarProps,
    'selectionMode'
> {
    selectionMode: MobileSelectionMode;
}

/** A 44px square on the inverted bar, the smallest target a thumb gets. */
const ICON_BUTTON_CLASS =
    'size-11 rounded-xl bg-primary-foreground/10 text-primary-foreground shadow-none hover:bg-primary-foreground/20 focus-visible:ring-primary-foreground/30 aria-expanded:bg-primary-foreground/20';

const MENU_ITEM_CLASS =
    'h-12 gap-3 rounded-[10px] px-3 text-[15px] focus:bg-primary-foreground/10 focus:text-primary-foreground [&_svg:not([class*=text-])]:text-primary-foreground/70';

/**
 * The phone's bulk-actions bar: one compact row pinned to the bottom while
 * selection mode is on, with Category and Labels opening their pickers as
 * bottom drawers and the rest behind a ⋯ menu. No keyboard shortcuts here.
 */
export function MobileBulkActionsBar({
    selectionMode,
    selectedCount,
    isSelectingAll = false,
    categories,
    labels = [],
    onCategoryChange,
    onLabelsChange,
    onLabelCreated,
    onDelete,
    onReEvaluateRules,
    onSelectAll,
    onSelectOnlyVisible,
    isUpdating = false,
}: MobileBulkActionsBarProps) {
    if (!selectionMode.isActive) {
        return null;
    }

    const hasSelection = selectedCount > 0 || isSelectingAll;
    const actionsDisabled = !hasSelection || isUpdating;
    const countLabel = isSelectingAll
        ? __('All matching filters')
        : hasSelection
          ? `${selectedCount} ${__('selected')}`
          : __('Tap to select');
    const drawerDescription = hasSelection ? countLabel : undefined;

    function selectAllMatching() {
        if (!selectionMode.allVisibleSelected) {
            selectionMode.toggleAllVisible();
        }
        onSelectAll?.();
    }

    return (
        <div
            role="toolbar"
            aria-label={__('Selection actions')}
            className="fixed inset-x-3 bottom-[calc(20px+env(safe-area-inset-bottom))] z-50 flex h-[60px] animate-in items-center gap-1 rounded-[18px] bg-primary py-1.5 pr-1.5 pl-4 text-primary-foreground shadow-[0_12px_32px_rgba(0,0,0,0.22)] slide-in-from-bottom-5 fade-in"
            data-test="mobile-bulk-actions-bar"
        >
            <span
                role="status"
                className={cn(
                    'min-w-0 flex-1 truncate text-[15px] font-semibold',
                    !hasSelection && 'font-medium text-primary-foreground/60',
                )}
                data-testid="bulk-selected-count"
            >
                {countLabel}
            </span>

            <BulkCategorySelect
                categories={categories}
                onCategoryChange={onCategoryChange}
                drawer={{
                    title: __('Change category'),
                    description: drawerDescription,
                }}
                trigger={
                    <Button
                        type="button"
                        disabled={actionsDisabled}
                        className="h-11 gap-2 rounded-xl bg-primary-foreground px-3.5 font-semibold text-primary shadow-none hover:bg-primary-foreground/90 focus-visible:ring-primary-foreground/30"
                        data-testid="bulk-category-trigger"
                    >
                        <LayoutGrid />
                        {__('Category')}
                    </Button>
                }
            />

            <BulkLabelSelect
                labels={labels}
                onLabelsChange={onLabelsChange}
                onLabelCreated={onLabelCreated}
                drawer={{
                    title: __('Add labels'),
                    description: drawerDescription,
                }}
                trigger={
                    <Button
                        type="button"
                        size="icon"
                        disabled={actionsDisabled}
                        aria-label={__('Labels')}
                        className={ICON_BUTTON_CLASS}
                        data-testid="bulk-labels-trigger"
                    >
                        <Tag />
                    </Button>
                }
            />

            <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                    <Button
                        type="button"
                        size="icon"
                        disabled={isUpdating}
                        aria-label={__('More actions')}
                        className={ICON_BUTTON_CLASS}
                        data-testid="bulk-more-actions"
                    >
                        <MoreHorizontal />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    side="top"
                    align="end"
                    sideOffset={10}
                    className="w-[264px] rounded-2xl border-0 bg-primary p-1.5 text-primary-foreground shadow-[0_12px_32px_rgba(0,0,0,0.24)]"
                >
                    <DropdownMenuItem
                        className={MENU_ITEM_CLASS}
                        // With every matching row selected, unticking the
                        // loaded ones alone would leave the filter selected.
                        onSelect={() =>
                            isSelectingAll
                                ? selectionMode.deselectAll()
                                : selectionMode.toggleAllVisible()
                        }
                    >
                        <ListChecks />
                        {isSelectingAll || selectionMode.allVisibleSelected
                            ? __('Deselect all')
                            : __('Select all visible')}
                    </DropdownMenuItem>
                    {onSelectAll &&
                        (isSelectingAll ? (
                            <DropdownMenuItem
                                className={MENU_ITEM_CLASS}
                                onSelect={() => onSelectOnlyVisible?.()}
                            >
                                <CheckCheck />
                                {__('Only visible')}
                            </DropdownMenuItem>
                        ) : (
                            <DropdownMenuItem
                                className={MENU_ITEM_CLASS}
                                onSelect={selectAllMatching}
                                data-testid="bulk-select-all"
                            >
                                <Filter />
                                {__('Select all matching filters')}
                            </DropdownMenuItem>
                        ))}
                    <DropdownMenuSeparator className="mx-2 bg-primary-foreground/15" />
                    <DropdownMenuItem
                        className={MENU_ITEM_CLASS}
                        disabled={actionsDisabled}
                        onSelect={() => onReEvaluateRules()}
                    >
                        <WandSparkles />
                        {__('Re-evaluate rules')}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        className={cn(
                            MENU_ITEM_CLASS,
                            'text-red-300 focus:text-red-200 dark:text-red-700 dark:focus:text-red-800 [&_svg]:!text-current',
                        )}
                        disabled={actionsDisabled || isSelectingAll}
                        onSelect={() => onDelete()}
                    >
                        <Trash2 />
                        {selectedCount > 0 && !isSelectingAll
                            ? __('Delete :count', { count: selectedCount })
                            : __('Delete')}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator className="mx-2 bg-primary-foreground/15" />
                    <DropdownMenuItem
                        className={MENU_ITEM_CLASS}
                        onSelect={() => selectionMode.exit()}
                    >
                        <X />
                        {__('Done')}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}
