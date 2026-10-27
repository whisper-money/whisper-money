import * as Icons from 'lucide-react';
import { useMemo, useState } from 'react';

import { BankLogo } from '@/components/bank-logo';
import { CategoryIcon } from '@/components/shared/category-combobox';
import { LabelIcon } from '@/components/shared/label-icon';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    buildCategoryTree,
    type CategoryNode,
    categorySelectionState,
    flattenCategoryTree,
    searchCategoryTree,
    toggleCategorySelection,
} from '@/lib/category-tree';
import { cn } from '@/lib/utils';
import { type Account } from '@/types/account';
import { type Category, getCategoryColorClasses } from '@/types/category';
import { getLabelColorClasses, type Label } from '@/types/label';
import { type UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';

/** The category id the filters use for "no category". */
export const UNCATEGORIZED_CATEGORY_ID = 'uncategorized';

/** The ids with `id` added, or taken out when it was there. */
export function toggleId<T>(ids: T[], id: T): T[] {
    return ids.includes(id) ? ids.filter((item) => item !== id) : [...ids, id];
}

/**
 * The category tree flattened, parents before their children, with the
 * branches selected when the list mounted sorted first: ticking one never
 * reshuffles the list under the finger.
 */
export function useSelectedFirstCategoryTree(
    categories: Category[],
    selectedIds: string[],
): CategoryNode[] {
    const [orderSnapshot] = useState(() => new Set(selectedIds));

    return useMemo(() => {
        // A branch sorts to the top when it (or a descendant) was selected.
        const branchSelected = new Set<string>();
        const parentOf = new Map(categories.map((c) => [c.id, c.parent_id]));
        for (const id of orderSnapshot) {
            let current: string | null | undefined = id;
            let guard = 0;
            while (current != null && guard++ < 10) {
                branchSelected.add(current);
                current = parentOf.get(current);
            }
        }

        const compare = (a: Category, b: Category) => {
            const aSelected = branchSelected.has(a.id);
            const bSelected = branchSelected.has(b.id);
            if (aSelected !== bSelected) {
                return aSelected ? -1 : 1;
            }
            return a.name.localeCompare(b.name);
        };

        return flattenCategoryTree(buildCategoryTree(categories, compare));
    }, [categories, orderSnapshot]);
}

interface CategoryFacetListProps {
    categories: Category[];
    selectedIds: string[];
    onChange: (categoryIds: string[]) => void;
}

/**
 * The searchable category tree with a checkbox per category, plus
 * "Uncategorized". Selected branches sort first, as they were when the list
 * mounted, so ticking one never reshuffles the list under the cursor.
 */
export function CategoryFacetList({
    categories,
    selectedIds,
    onChange,
}: CategoryFacetListProps) {
    const [search, setSearch] = useState('');
    const categoryTree = useSelectedFirstCategoryTree(categories, selectedIds);

    const selectedSet = useMemo(() => new Set(selectedIds), [selectedIds]);
    const visibleTree = useMemo(
        () => searchCategoryTree(categoryTree, search),
        [categoryTree, search],
    );

    const showUncategorizedRow =
        search.trim() === '' ||
        'uncategorized'.includes(search.trim().toLowerCase());
    const hasResults = visibleTree.length > 0 || showUncategorizedRow;

    function toggle(categoryId: string) {
        onChange(toggleCategorySelection(selectedIds, categoryId, categories));
    }

    return (
        <Command shouldFilter={false}>
            <CommandInput
                placeholder={__('Search categories...')}
                value={search}
                onValueChange={setSearch}
            />
            <CommandList>
                {!hasResults && (
                    <div className="py-6 text-center text-sm text-muted-foreground">
                        {__('No category found.')}
                    </div>
                )}
                {showUncategorizedRow && (
                    <CommandItem
                        onSelect={() => toggle(UNCATEGORIZED_CATEGORY_ID)}
                    >
                        <Checkbox
                            checked={selectedSet.has(UNCATEGORIZED_CATEGORY_ID)}
                            className="pointer-events-none mr-1"
                        />
                        <div className="flex items-center gap-2">
                            <div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <Icons.HelpCircle className="h-3 w-3 text-zinc-500" />
                            </div>
                            {__('Uncategorized')}
                        </div>
                    </CommandItem>
                )}
                {visibleTree.map((category) => {
                    const state = categorySelectionState(
                        category.id,
                        selectedSet,
                        categories,
                    );
                    const colorClasses = getCategoryColorClasses(
                        category.color,
                    );
                    return (
                        <CommandItem
                            key={category.id}
                            value={category.id}
                            onSelect={() => toggle(category.id)}
                            style={{
                                paddingLeft: `${0.5 + category.depth * 1.25}rem`,
                            }}
                        >
                            <Checkbox
                                checked={
                                    state === 'indeterminate'
                                        ? 'indeterminate'
                                        : state === 'checked'
                                }
                                className="pointer-events-none mr-1"
                            />
                            <div className="flex min-w-0 items-center gap-2">
                                <div
                                    className={cn(
                                        'flex h-5 w-5 shrink-0 items-center justify-center rounded-full',
                                        colorClasses.bg,
                                    )}
                                >
                                    <CategoryIcon category={category} />
                                </div>
                                <span className="truncate">
                                    {category.name}
                                </span>
                            </div>
                        </CommandItem>
                    );
                })}
            </CommandList>
        </Command>
    );
}

interface LabelFacetListProps {
    labels: Label[];
    selectedIds: string[];
    onChange: (labelIds: string[]) => void;
}

/** The searchable labels with a checkbox each. */
export function LabelFacetList({
    labels,
    selectedIds,
    onChange,
}: LabelFacetListProps) {
    return (
        <Command>
            <CommandInput placeholder={__('Search labels...')} />
            <CommandList>
                <CommandEmpty>{__('No labels found.')}</CommandEmpty>
                {labels.map((label) => {
                    const colorClasses = getLabelColorClasses(label.color);
                    return (
                        <CommandItem
                            key={label.id}
                            onSelect={() =>
                                onChange(toggleId(selectedIds, label.id))
                            }
                        >
                            <Checkbox
                                checked={selectedIds.includes(label.id)}
                                className="pointer-events-none mr-1"
                            />
                            <div className="flex items-center gap-2">
                                <div
                                    className={cn(
                                        'flex h-5 w-5 items-center justify-center rounded-full',
                                        colorClasses.bg,
                                    )}
                                >
                                    <LabelIcon
                                        label={label}
                                        className={cn(
                                            'h-3 w-3',
                                            colorClasses.text,
                                        )}
                                    />
                                </div>
                                <span>{label.name}</span>
                            </div>
                        </CommandItem>
                    );
                })}
            </CommandList>
        </Command>
    );
}

interface AccountFacetListProps {
    accounts: Account[];
    selectedIds: UUID[];
    onChange: (accountIds: UUID[]) => void;
}

/** The searchable accounts, with their bank's logo and a checkbox each. */
export function AccountFacetList({
    accounts,
    selectedIds,
    onChange,
}: AccountFacetListProps) {
    return (
        <Command>
            <CommandInput placeholder={__('Search accounts...')} />
            <CommandList>
                <CommandEmpty>{__('No accounts found.')}</CommandEmpty>
                {accounts.map((account) => (
                    <CommandItem
                        key={account.id}
                        value={`${account.name} ${account.id}`}
                        onSelect={() =>
                            onChange(toggleId(selectedIds, account.id))
                        }
                    >
                        <Checkbox
                            checked={selectedIds.includes(account.id)}
                            className="pointer-events-none mr-1"
                        />
                        <div className="flex min-w-0 items-center gap-2">
                            <BankLogo
                                src={account.bank?.logo}
                                name={account.bank?.name}
                                fallback="icon"
                                className="h-5 w-5 shrink-0 rounded-full"
                            />
                            <span className="truncate">{account.name}</span>
                        </div>
                    </CommandItem>
                ))}
            </CommandList>
        </Command>
    );
}
