import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    buildCategoryTree,
    flattenCategoryTree,
    getDescendantIds,
} from '@/lib/category-tree';
import { type SharedData } from '@/types';
import { type Category } from '@/types/category';
import { UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';

const ROOT_VALUE = '__root__';

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
    const enabled = usePage<SharedData>().props.features.categoryTree;

    const options = useMemo(() => {
        const excluded = new Set<UUID>();
        if (excludeId) {
            excluded.add(excludeId);
            for (const id of getDescendantIds(excludeId, categories)) {
                excluded.add(id);
            }
        }

        // A parent must leave room for at least one level beneath it.
        const tree = buildCategoryTree(categories);
        return flattenCategoryTree(tree).filter(
            (node) => !excluded.has(node.id) && node.depth < 2,
        );
    }, [categories, excludeId]);

    const byId = useMemo(
        () => new Map(categories.map((c) => [c.id, c])),
        [categories],
    );

    if (!enabled) {
        return null;
    }

    return (
        <div className="space-y-2">
            <Label htmlFor="parent_id">{__('Parent category')}</Label>
            <input type="hidden" name="parent_id" value={value ?? ''} />
            <Select
                value={value ?? ROOT_VALUE}
                onValueChange={(next) =>
                    onChange(
                        next === ROOT_VALUE ? null : (byId.get(next) ?? null),
                    )
                }
            >
                <SelectTrigger>
                    <SelectValue placeholder={__('None (top level)')} />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ROOT_VALUE}>
                        {__('None (top level)')}
                    </SelectItem>
                    {options.map((node) => (
                        <SelectItem key={node.id} value={node.id}>
                            <span
                                style={{
                                    paddingLeft: `${node.depth * 0.75}rem`,
                                }}
                            >
                                {node.name}
                            </span>
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
                {__(
                    'Child categories inherit their parent’s type and cashflow settings.',
                )}
            </p>
            <InputError message={error} />
        </div>
    );
}
