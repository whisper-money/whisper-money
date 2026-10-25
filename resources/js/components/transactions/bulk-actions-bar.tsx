import { Shortcut } from '@/components/shortcuts/shortcut';
import { ShortcutKbd } from '@/components/shortcuts/shortcut-kbd';
import { BulkCategorySelect } from '@/components/transactions/bulk-category-select';
import { BulkLabelSelect } from '@/components/transactions/bulk-label-select';
import { Button } from '@/components/ui/button';
import { useShortcut, useShortcutHint } from '@/hooks/use-shortcut';
import { type ShortcutId } from '@/lib/shortcut-catalog';
import { cn } from '@/lib/utils';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { __ } from '@/utils/i18n';
import {
    CheckCheck,
    LayoutGrid,
    type LucideIcon,
    Tag,
    Trash2,
    WandSparkles,
    X,
} from 'lucide-react';
import { type ComponentProps, type Ref, useRef } from 'react';

interface BulkActionsBarProps {
    selectedCount: number;
    isSelectingAll?: boolean;
    categories: Category[];
    labels?: Label[];
    onCategoryChange: (categoryId: number | null) => void;
    onLabelsChange: (labelIds: string[]) => void;
    onLabelCreated?: (label: Label) => void;
    onDelete: () => void;
    onReEvaluateRules: () => void;
    onSelectAll?: () => void;
    onClear: () => void;
    isUpdating?: boolean;
}

/**
 * The bar is inverted (primary-foreground on primary), so a `default` button
 * made transparent sits on it in both themes.
 */
const BAR_BUTTON_CLASS =
    'bg-transparent shadow-none hover:bg-primary-foreground/10 focus-visible:ring-primary-foreground/30';

/**
 * The chip's tint for a primary surface, set here rather than left to `Kbd`:
 * a `PopoverTrigger asChild` replaces the button's `data-slot`, which `Kbd`
 * reads to pick it.
 */
const BAR_KBD_CLASS = 'bg-primary-foreground/15 text-primary-foreground/70';

function assignRef<T>(ref: Ref<T> | undefined, node: T | null): void {
    if (typeof ref === 'function') {
        ref(node);
    } else if (ref) {
        ref.current = node;
    }
}

interface BulkActionButtonProps extends ComponentProps<typeof Button> {
    shortcut: ShortcutId;
    icon: LucideIcon;
    label: string;
}

/**
 * A labelled action of the bar that answers its shortcut by clicking itself,
 * so a key does exactly what a click does — open a picker included. Props and
 * ref pass through to the button, so a `PopoverTrigger asChild` can wrap it.
 */
function BulkActionButton({
    shortcut,
    icon: Icon,
    label,
    className,
    disabled,
    ref,
    ...props
}: BulkActionButtonProps) {
    const buttonRef = useRef<HTMLButtonElement | null>(null);
    const hint = useShortcutHint(shortcut);

    useShortcut(shortcut, () => buttonRef.current?.click(), {
        enabled: !disabled,
    });

    return (
        <Button
            {...props}
            ref={(node) => {
                buttonRef.current = node;
                assignRef(ref, node);
            }}
            type="button"
            disabled={disabled}
            aria-keyshortcuts={hint?.ariaKeyShortcuts}
            className={cn(BAR_BUTTON_CLASS, 'rounded-[9px] px-2.5', className)}
        >
            <Icon />
            {label}
            <ShortcutKbd id={shortcut} className={BAR_KBD_CLASS} />
        </Button>
    );
}

function BarDivider() {
    return (
        <span
            aria-hidden="true"
            className="mx-0.5 h-[22px] w-px bg-primary-foreground/15"
        />
    );
}

export function BulkActionsBar({
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
    onClear,
    isUpdating = false,
}: BulkActionsBarProps) {
    const clearHint = useShortcutHint('transaction-selection.clear');

    useShortcut('transaction-selection.clear', onClear, {
        enabled: selectedCount > 0 && !isUpdating,
    });

    if (selectedCount < 1) {
        return null;
    }

    return (
        // Fixed, but over the content column: from the edge of the sidebar
        // (expanded or collapsed to icons) to the inset's right margin.
        <div className="pointer-events-none fixed right-0 bottom-6 left-0 z-20 flex animate-in justify-center px-4 transition-[left] duration-200 ease-linear slide-in-from-bottom-5 fade-in md:right-2 md:left-(--sidebar-width) md:group-has-[[data-slot=sidebar][data-state=collapsed]]/sidebar-wrapper:left-[calc(var(--sidebar-width-icon)+--spacing(4))]">
            <div
                role="toolbar"
                aria-label={__('Selection actions')}
                className="pointer-events-auto flex flex-wrap items-center gap-1 rounded-[14px] bg-primary p-1.5 text-primary-foreground shadow-[0_12px_32px_rgba(0,0,0,0.2),0_0_0_1px_rgba(0,0,0,0.04)]"
                data-test="bulk-actions-bar"
            >
                <div className="flex h-9 items-center gap-2.5 pr-2.5 pl-2 text-sm">
                    {isSelectingAll ? (
                        <span className="flex items-center gap-2 font-medium">
                            <CheckCheck className="size-4" />
                            {__('All matching filters')}
                        </span>
                    ) : (
                        <>
                            <span
                                role="status"
                                className="flex items-center gap-2.5"
                            >
                                <span
                                    className="flex h-[22px] min-w-[26px] items-center justify-center rounded-md bg-primary-foreground px-[7px] text-[13px] font-semibold text-primary tabular-nums"
                                    data-testid="bulk-selected-count"
                                >
                                    {selectedCount}
                                </span>
                                <span className="font-medium whitespace-nowrap">
                                    {__('selected')}
                                </span>
                            </span>
                            {onSelectAll && (
                                <button
                                    type="button"
                                    onClick={onSelectAll}
                                    disabled={isUpdating}
                                    className="h-7 cursor-pointer rounded-md px-2 text-[13px] whitespace-nowrap text-primary-foreground/60 underline underline-offset-3 outline-none hover:text-primary-foreground focus-visible:ring-[3px] focus-visible:ring-primary-foreground/30 disabled:pointer-events-none disabled:opacity-50"
                                    data-testid="bulk-select-all"
                                >
                                    {__('Select all matching filters')}
                                </button>
                            )}
                        </>
                    )}
                </div>

                <BarDivider />

                <BulkCategorySelect
                    categories={categories}
                    onCategoryChange={onCategoryChange}
                    trigger={
                        <BulkActionButton
                            shortcut="transaction-selection.category"
                            icon={LayoutGrid}
                            label={__('Category')}
                            disabled={isUpdating}
                            data-testid="bulk-category-trigger"
                        />
                    }
                />

                <BulkLabelSelect
                    labels={labels}
                    onLabelsChange={onLabelsChange}
                    onLabelCreated={onLabelCreated}
                    trigger={
                        <BulkActionButton
                            shortcut="transaction-selection.labels"
                            icon={Tag}
                            label={__('Labels')}
                            disabled={isUpdating}
                            data-testid="bulk-labels-trigger"
                        />
                    }
                />

                <BulkActionButton
                    shortcut="transaction-selection.re-evaluate-rules"
                    icon={WandSparkles}
                    label={__('Re-evaluate rules')}
                    onClick={onReEvaluateRules}
                    disabled={isUpdating}
                />

                {!isSelectingAll && (
                    <>
                        <BulkActionButton
                            shortcut="transaction-selection.delete"
                            icon={Trash2}
                            label={__('Delete')}
                            onClick={onDelete}
                            disabled={isUpdating}
                            className="text-red-300 hover:text-red-200 dark:text-red-700 dark:hover:text-red-800"
                        />
                        <Shortcut
                            id="transaction-selection.delete-forward"
                            onTrigger={onDelete}
                            enabled={!isUpdating}
                        />
                    </>
                )}

                <BarDivider />

                <Button
                    type="button"
                    onClick={onClear}
                    disabled={isUpdating}
                    aria-label={__('Clear selection')}
                    aria-keyshortcuts={clearHint?.ariaKeyShortcuts}
                    className={cn(
                        BAR_BUTTON_CLASS,
                        'gap-1.5 rounded-[9px] px-2 text-primary-foreground/75 hover:text-primary-foreground',
                    )}
                >
                    <ShortcutKbd
                        id="transaction-selection.clear"
                        className={BAR_KBD_CLASS}
                    />
                    <X />
                </Button>
            </div>
        </div>
    );
}
