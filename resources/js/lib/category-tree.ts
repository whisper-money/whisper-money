import { Category } from '@/types/category';
import { UUID } from '@/types/uuid';

export interface CategoryNode extends Category {
    depth: number;
    children: CategoryNode[];
}

/**
 * Build a nested tree from a flat list of categories, sorting siblings by name
 * at every level. Orphans (a parent that is missing from the list) are treated
 * as roots so nothing silently disappears.
 */
export function buildCategoryTree(categories: Category[]): CategoryNode[] {
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
        nodes.sort((a, b) => a.name.localeCompare(b.name));
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
