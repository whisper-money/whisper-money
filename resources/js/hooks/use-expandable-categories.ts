import { useCallback, useEffect, useRef, useState } from 'react';

export interface ExpandableCategories<T> {
    isExpanded: (id: string) => boolean;
    isLoading: (id: string) => boolean;
    getChildren: (id: string) => T[];
    toggle: (id: string) => void;
}

/**
 * Tracks which parent categories are expanded and lazily fetches their
 * children once, caching the result. Expansions and the cache are cleared
 * whenever `resetKey` changes (e.g. the selected period).
 */
export function useExpandableCategories<T>(
    fetchChildren: (categoryId: string) => Promise<T[]>,
    resetKey?: unknown,
): ExpandableCategories<T> {
    const [expanded, setExpanded] = useState<Set<string>>(new Set());
    const [childrenById, setChildrenById] = useState<Record<string, T[]>>({});
    const [loadingIds, setLoadingIds] = useState<Set<string>>(new Set());

    const fetchRef = useRef(fetchChildren);
    fetchRef.current = fetchChildren;
    const loadedRef = useRef<Set<string>>(new Set());

    useEffect(() => {
        setExpanded(new Set());
        setChildrenById({});
        setLoadingIds(new Set());
        loadedRef.current = new Set();
    }, [resetKey]);

    const toggle = useCallback((id: string) => {
        setExpanded((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });

        if (loadedRef.current.has(id)) {
            return;
        }
        loadedRef.current.add(id);
        setLoadingIds((prev) => new Set(prev).add(id));

        fetchRef
            .current(id)
            .then((data) =>
                setChildrenById((current) => ({ ...current, [id]: data })),
            )
            .catch((error) => {
                loadedRef.current.delete(id);
                console.error('Failed to load subcategories:', error);
            })
            .finally(() =>
                setLoadingIds((prev) => {
                    const next = new Set(prev);
                    next.delete(id);
                    return next;
                }),
            );
    }, []);

    const isExpanded = useCallback(
        (id: string) => expanded.has(id),
        [expanded],
    );
    const isLoading = useCallback(
        (id: string) => loadingIds.has(id),
        [loadingIds],
    );
    const getChildren = useCallback(
        (id: string) => childrenById[id] ?? [],
        [childrenById],
    );

    return { isExpanded, isLoading, getChildren, toggle };
}
