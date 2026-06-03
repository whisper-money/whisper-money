import { Category } from '@/types/category';
import { UUID } from '@/types/uuid';

export interface CategoryNode extends Category {
    depth: number;
    children: CategoryNode[];
}

const compareByName = (a: Category, b: Category): number =>
    a.name.localeCompare(b.name);

/**
 * Build a nested tree from a flat list of categories. Siblings are sorted with
 * `compare` at every level (defaults to name), so children always stay grouped
 * under their parent regardless of the chosen order. Orphans (a parent that is
 * missing from the list) are treated as roots so nothing silently disappears.
 */
export function buildCategoryTree(
    categories: Category[],
    compare: (a: Category, b: Category) => number = compareByName,
): CategoryNode[] {
    const byId = new Map<UUID, CategoryNode>();
    for (const category of categories) {
        byId.set(category.id, { ...category, depth: 0, children: [] });
    }

    const roots: CategoryNode[] = [];
    for (const node of byId.values()) {
        const parent =
            node.parent_id != null ? byId.get(node.parent_id) : undefined;
        if (parent) {
            parent.children.push(node);
        } else {
            roots.push(node);
        }
    }

    const sortAndDepth = (nodes: CategoryNode[], depth: number) => {
        nodes.sort(compare);
        for (const node of nodes) {
            node.depth = depth;
            sortAndDepth(node.children, depth + 1);
        }
    };
    sortAndDepth(roots, 0);

    return roots;
}

/**
 * Flatten a tree back into a list in depth-first display order, carrying the
 * computed depth so callers can indent rows.
 */
export function flattenCategoryTree(nodes: CategoryNode[]): CategoryNode[] {
    const flat: CategoryNode[] = [];
    const walk = (list: CategoryNode[]) => {
        for (const node of list) {
            flat.push(node);
            walk(node.children);
        }
    };
    walk(nodes);

    return flat;
}

/**
 * All descendant ids of a category (excluding itself), resolved from a flat
 * list via a parent index.
 */
export function getDescendantIds(
    categoryId: UUID,
    categories: Category[],
): UUID[] {
    const childrenByParent = new Map<UUID, UUID[]>();
    for (const category of categories) {
        if (category.parent_id == null) {
            continue;
        }
        const siblings = childrenByParent.get(category.parent_id) ?? [];
        siblings.push(category.id);
        childrenByParent.set(category.parent_id, siblings);
    }

    const result: UUID[] = [];
    const stack = [...(childrenByParent.get(categoryId) ?? [])];
    while (stack.length > 0) {
        const id = stack.pop()!;
        result.push(id);
        stack.push(...(childrenByParent.get(id) ?? []));
    }

    return result;
}

export type CategorySelectionState = 'checked' | 'indeterminate' | 'unchecked';

interface TreeIndex {
    parentOf: Map<UUID, UUID | null>;
    childrenOf: Map<UUID, UUID[]>;
}

function indexTree(categories: Category[]): TreeIndex {
    const parentOf = new Map<UUID, UUID | null>();
    const childrenOf = new Map<UUID, UUID[]>();
    for (const category of categories) {
        parentOf.set(category.id, category.parent_id);
        if (category.parent_id != null) {
            const siblings = childrenOf.get(category.parent_id) ?? [];
            siblings.push(category.id);
            childrenOf.set(category.parent_id, siblings);
        }
    }

    return { parentOf, childrenOf };
}

function collectDescendants(id: UUID, childrenOf: Map<UUID, UUID[]>): UUID[] {
    const result: UUID[] = [];
    const stack = [...(childrenOf.get(id) ?? [])];
    while (stack.length > 0) {
        const current = stack.pop()!;
        result.push(current);
        stack.push(...(childrenOf.get(current) ?? []));
    }

    return result;
}

/**
 * Tri-state for a category in a tree multi-select: a category is checked when
 * it (or an ancestor) is in the selected set, indeterminate when only some
 * descendants are, otherwise unchecked.
 */
export function categorySelectionState(
    id: UUID,
    selected: Set<UUID>,
    categories: Category[],
): CategorySelectionState {
    const { parentOf, childrenOf } = indexTree(categories);

    let current: UUID | null | undefined = id;
    let guard = 0;
    while (current != null && guard++ < 10) {
        if (selected.has(current)) {
            return 'checked';
        }
        current = parentOf.get(current);
    }

    if (collectDescendants(id, childrenOf).some((d) => selected.has(d))) {
        return 'indeterminate';
    }

    return 'unchecked';
}

/**
 * Toggle a category in a tree multi-select selection.
 *
 * Selecting a category selects its whole subtree (one id covers it; the backend
 * expands it when filtering). Unselecting a covered child first pushes the
 * covering ancestor down into its children, so siblings stay selected while the
 * one child is removed. Selecting a child never auto-selects its parent, so
 * picking a child won't pull in the parent's directly-assigned transactions —
 * click the parent row to select the whole subtree at once.
 */
export function toggleCategorySelection(
    selected: UUID[],
    id: UUID,
    categories: Category[],
): UUID[] {
    const set = new Set(selected);
    const { parentOf, childrenOf } = indexTree(categories);

    const isChecked = categorySelectionState(id, set, categories) === 'checked';

    if (isChecked) {
        // Push every covering ancestor down to its direct children so removing
        // a single node leaves its siblings selected.
        const chain: UUID[] = [];
        let ancestor = parentOf.get(id);
        let guard = 0;
        while (ancestor != null && guard++ < 10) {
            chain.unshift(ancestor);
            ancestor = parentOf.get(ancestor);
        }
        for (const node of chain) {
            if (set.has(node)) {
                set.delete(node);
                for (const child of childrenOf.get(node) ?? []) {
                    set.add(child);
                }
            }
        }

        set.delete(id);
        for (const descendant of collectDescendants(id, childrenOf)) {
            set.delete(descendant);
        }

        return [...set];
    }

    // Select the whole subtree: one id covers it, so drop any now-redundant
    // descendants. Ancestors are left untouched so selecting a child never
    // pulls in a parent (and its directly-assigned transactions).
    for (const descendant of collectDescendants(id, childrenOf)) {
        set.delete(descendant);
    }
    set.add(id);

    return [...set];
}

/**
 * Build the "Parent > Child" display path for a category.
 */
export function getCategoryPath(
    categoryId: UUID,
    categories: Category[],
    separator = ' › ',
): string {
    const byId = new Map<UUID, Category>(categories.map((c) => [c.id, c]));
    const names: string[] = [];
    let current: Category | undefined = byId.get(categoryId);
    let guard = 0;
    while (current && guard++ < 10) {
        names.unshift(current.name);
        current =
            current.parent_id != null ? byId.get(current.parent_id) : undefined;
    }

    return names.join(separator);
}
