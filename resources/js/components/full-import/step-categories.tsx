import {
    Notice,
    Pill,
    SectionCard,
    SectionRow,
    WizardScreen,
} from '@/components/full-import/full-import-layout';
import { CategoryCombobox } from '@/components/shared/category-combobox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    countLabel,
    formatCount,
    transactionCount,
} from '@/lib/full-import-format';
import { isOwnTransferNode } from '@/lib/full-import-plan';
import {
    CATEGORY_TYPES,
    getCategoryTypeLabel,
    type Category,
    type CategoryType,
} from '@/types/category';
import {
    type CategoryPlanEntry,
    type FileCategoryNode,
    type TransferTarget,
} from '@/types/full-import';
import { __ } from '@/utils/i18n';
import { ArrowLeftRight, Sparkles } from 'lucide-react';
import { type ReactNode } from 'react';

/** The None option of a category picker, which the combobox reports as 'null'. */
const NONE = 'null';

export interface TransferSlot {
    count: number;
    categoryId: string | null;
    target: TransferTarget;
    onChange: (categoryId: string | null) => void;
}

interface StepCategoriesProps {
    eyebrow: string;
    nodes: FileCategoryNode[];
    plan: Record<string, CategoryPlanEntry>;
    onChange: (nodeId: string, entry: Partial<CategoryPlanEntry>) => void;
    categories: Category[];
    own: TransferSlot;
    ignored: TransferSlot;
    uncategorized: number;
    aiAvailable: boolean;
    locale: string;
    footer: ReactNode;
}

/** "Alimentación › Restaurantes": a node with the names of its parents. */
function nodePath(node: FileCategoryNode, nodes: FileCategoryNode[]): string {
    const parent = nodes.find((other) => other.id === node.parentId);

    return parent ? `${nodePath(parent, nodes)} › ${node.name}` : node.name;
}

function TransferPicker({
    label,
    slot,
    categories,
}: {
    label: string;
    slot: TransferSlot;
    categories: Category[];
}) {
    return (
        <CategoryCombobox
            value={slot.categoryId ?? NONE}
            onValueChange={(value) =>
                slot.onChange(value === NONE ? null : value)
            }
            categories={categories}
            emptyOptionLabel={__('Create «:name»', { name: slot.target.name })}
            triggerClassName="sm:w-72"
            data-testid={label}
        />
    );
}

export function StepCategories({
    eyebrow,
    nodes,
    plan,
    onChange,
    categories,
    own,
    ignored,
    uncategorized,
    aiAvailable,
    locale,
    footer,
}: StepCategoriesProps) {
    const regular = nodes.filter((node) => !isOwnTransferNode(node, nodes));
    const matched = regular.filter((node) => plan[node.id].action === 'match');
    const created = regular.filter((node) => plan[node.id].action === 'create');
    const transfers = categories.filter(
        (category) => category.type === 'transfer',
    );

    const picker = (node: FileCategoryNode) => (
        <CategoryCombobox
            value={plan[node.id].categoryId ?? NONE}
            onValueChange={(value) =>
                onChange(
                    node.id,
                    value === NONE
                        ? {
                              action: 'create',
                              categoryId: null,
                              matchKind: null,
                          }
                        : {
                              action: 'match',
                              categoryId: value,
                              matchKind: null,
                              type:
                                  categories.find(
                                      (category) => category.id === value,
                                  )?.type ?? plan[node.id].type,
                          },
                )
            }
            categories={categories}
            emptyOptionLabel={__('Create new')}
            triggerClassName="sm:w-72"
        />
    );

    const countLine = (node: FileCategoryNode) =>
        node.count > 0
            ? transactionCount(node.count, locale)
            : __('Main category');

    return (
        <WizardScreen
            eyebrow={eyebrow}
            title={__('Your categories')}
            description={__(
                'The ones that already exist here are merged with yours. The rest are created, with their subcategories.',
            )}
            footer={footer}
        >
            <div className="flex flex-wrap gap-2">
                <Pill>
                    {countLabel(
                        matched.length,
                        __('1 merged with yours'),
                        __(':count merged with yours', {
                            count: matched.length,
                        }),
                    )}
                </Pill>
                <Pill tone="info">
                    {countLabel(
                        created.length,
                        __('1 new'),
                        __(':count new', { count: created.length }),
                    )}
                </Pill>
                {uncategorized > 0 && (
                    <Pill>
                        {countLabel(
                            uncategorized,
                            __('1 transaction without a category'),
                            __(':count transactions without a category', {
                                count: formatCount(uncategorized, locale),
                            }),
                        )}
                    </Pill>
                )}
            </div>

            {(own.count > 0 || ignored.count > 0) && (
                <SectionCard
                    label={
                        <>
                            <ArrowLeftRight className="size-4" />
                            {__('Transfers')}
                        </>
                    }
                >
                    {own.count > 0 && (
                        <SectionRow
                            title={
                                nodes.find((node) =>
                                    isOwnTransferNode(node, nodes),
                                )?.name ?? __('Own transfers')
                            }
                            meta={transactionCount(own.count, locale)}
                        >
                            <TransferPicker
                                label="full-import-own-transfers"
                                slot={own}
                                categories={categories}
                            />
                        </SectionRow>
                    )}
                    {ignored.count > 0 && (
                        <SectionRow
                            title={__('Marked as «Ignored»')}
                            meta={transactionCount(ignored.count, locale)}
                        >
                            <TransferPicker
                                label="full-import-ignored"
                                slot={ignored}
                                categories={transfers}
                            />
                            <span className="text-[13px] text-muted-foreground">
                                {__(
                                    "They didn't count in your previous app. Here they go to a transfer, so they count as neither spending nor income.",
                                )}
                            </span>
                        </SectionRow>
                    )}
                </SectionCard>
            )}

            {matched.length > 0 && (
                <SectionCard label={__('Already in Whisper Money')}>
                    {matched.map((node) => (
                        <SectionRow
                            key={node.id}
                            title={
                                <>
                                    {nodePath(node, nodes)}
                                    {plan[node.id].matchKind === 'similar' && (
                                        <Pill tone="warning">
                                            {__('Similar, review it')}
                                        </Pill>
                                    )}
                                </>
                            }
                            meta={countLine(node)}
                        >
                            {picker(node)}
                        </SectionRow>
                    ))}
                </SectionCard>
            )}

            {created.length > 0 && (
                <SectionCard
                    label={
                        <>
                            {__('New categories')}
                            <span className="font-normal">
                                {__(
                                    'Created with an icon and a colour; you can change them later.',
                                )}
                            </span>
                        </>
                    }
                >
                    {created.map((node) => {
                        const parentCreated =
                            node.parentId !== null &&
                            plan[node.parentId]?.action === 'create';

                        return (
                            <div
                                key={node.id}
                                className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:px-5"
                                style={{
                                    paddingLeft: parentCreated
                                        ? `${1 + node.depth * 1.5}rem`
                                        : undefined,
                                }}
                            >
                                <div className="flex min-w-0 flex-1 flex-col">
                                    <span className="font-medium">
                                        {parentCreated && (
                                            <span className="mr-1.5 text-muted-foreground">
                                                ›
                                            </span>
                                        )}
                                        {parentCreated
                                            ? node.name
                                            : nodePath(node, nodes)}
                                    </span>
                                    <span className="text-[13px] text-muted-foreground">
                                        {countLine(node)}
                                    </span>
                                </div>
                                {picker(node)}
                                {node.parentId === null ? (
                                    <Select
                                        value={plan[node.id].type}
                                        onValueChange={(value) =>
                                            onChange(node.id, {
                                                type: value as CategoryType,
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            className="sm:w-36"
                                            aria-label={__('Type of :name', {
                                                name: node.name,
                                            })}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {CATEGORY_TYPES.map((type) => (
                                                <SelectItem
                                                    key={type}
                                                    value={type}
                                                >
                                                    {getCategoryTypeLabel(type)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                ) : (
                                    <span className="text-[13px] text-muted-foreground sm:w-36">
                                        {getCategoryTypeLabel(
                                            plan[node.id].type,
                                        )}
                                    </span>
                                )}
                            </div>
                        );
                    })}
                </SectionCard>
            )}

            {uncategorized > 0 && (
                <Notice icon={Sparkles}>
                    <span className="font-medium">
                        {countLabel(
                            uncategorized,
                            __('1 transaction has no category in the file'),
                            __(
                                ':count transactions have no category in the file',
                                { count: formatCount(uncategorized, locale) },
                            ),
                        )}
                    </span>
                    <span>
                        {aiAvailable
                            ? countLabel(
                                  uncategorized,
                                  __(
                                      'When the import finishes, the AI will categorize it with your categories, new ones included.',
                                  ),
                                  __(
                                      'When the import finishes, the AI will categorize them with your categories, new ones included.',
                                  ),
                              )
                            : countLabel(
                                  uncategorized,
                                  __(
                                      'It will stay uncategorized. With a paid plan, the AI categorizes it for you.',
                                  ),
                                  __(
                                      'They will stay uncategorized. With a paid plan, the AI categorizes them for you.',
                                  ),
                              )}
                    </span>
                </Notice>
            )}
        </WizardScreen>
    );
}
