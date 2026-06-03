import InputError from '@/components/input-error';
import { CategoryCombobox } from '@/components/shared/category-combobox';
import { Label } from '@/components/ui/label';
import { getDescendantIds } from '@/lib/category-tree';
import { type Category } from '@/types/category';
import { UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';

interface ParentCategoryFieldProps {
    categories: Category[];
    value: UUID | null;
    onChange: (parent: Category | null) => void;
    /** Category being edited — itself and its descendants can't be a parent. */
    excludeId?: UUID;
    error?: string;
}

export function ParentCategoryField({
    categories,
    value,
    onChange,
    excludeId,
    error,
}: ParentCategoryFieldProps) {
    const enabled =
        usePage<{ categoryTreeEnabled?: boolean }>().props
            .categoryTreeEnabled ?? false;

    const byId = useMemo(
        () => new Map(categories.map((c) => [c.id, c])),
        [categories],
    );

    // Eligible parents: anything that isn't this category or one of its
    // descendants (no cycles) and that still leaves room for a level below it.
    const eligibleParents = useMemo(() => {
        const excluded = new Set<UUID>();
        if (excludeId) {
            excluded.add(excludeId);
            for (const id of getDescendantIds(excludeId, categories)) {
                excluded.add(id);
            }
        }

        const depthOf = (category: Category): number => {
            let depth = 0;
            let current: Category | undefined = category;
            while (current?.parent_id != null && depth < 5) {
                current = byId.get(current.parent_id);
                depth += 1;
            }
            return depth;
        };

        return categories.filter(
            (category) => !excluded.has(category.id) && depthOf(category) < 2,
        );
    }, [categories, excludeId, byId]);

    if (!enabled) {
        return null;
    }

    return (
        <div className="space-y-2">
            <Label htmlFor="parent_id">{__('Parent category')}</Label>
            <input type="hidden" name="parent_id" value={value ?? ''} />
            <CategoryCombobox
                value={value ?? 'null'}
                onValueChange={(next) =>
                    onChange(next === 'null' ? null : (byId.get(next) ?? null))
                }
                categories={eligibleParents}
                emptyOptionLabel={__('None (top level)')}
                placeholder={__('None (top level)')}
            />
            <p className="text-xs text-muted-foreground">
                {__(
                    'Child categories inherit their parent’s type and cashflow settings.',
                )}
            </p>
            <InputError message={error} />
        </div>
    );
}
