import { CategoryIcon } from '@/components/shared/category-combobox';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Kbd } from '@/components/ui/kbd';
import { type AnimationState } from '@/hooks/use-categorize-transactions';
import { cn } from '@/lib/utils';
import { type Category, getCategoryColorClasses } from '@/types/category';
import { type DecryptedTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { type RefObject } from 'react';

interface CategorizerCommandProps {
    sortedCategories: Category[];
    animationState: AnimationState;
    currentTransaction: DecryptedTransaction | undefined;
    searchValue: string;
    onSearchChange: (value: string) => void;
    onCategorySelect: (category: Category) => void;
    commandInputRef: RefObject<HTMLInputElement | null>;
    disabled?: boolean;
}

export function CategorizerCommand({
    sortedCategories,
    animationState,
    currentTransaction,
    searchValue,
    onSearchChange,
    onCategorySelect,
    commandInputRef,
    disabled = false,
}: CategorizerCommandProps) {
    if (animationState === 'success' || !currentTransaction) {
        return null;
    }

    return (
        <div
            className={cn(
                'flex flex-col gap-4 px-6 pt-2 transition-all duration-300',
                animationState === 'exiting' && 'translate-y-[-10px] opacity-0',
                animationState === 'entering' &&
                    'animate-categorizer-command-enter',
                animationState === 'idle' && 'translate-y-0 opacity-100',
                disabled && 'pointer-events-none opacity-40',
            )}
        >
            <div className="flex flex-col gap-1">
                <h3 className="font-medium">{__('Assign a new category')}</h3>
                <p className="flex items-center gap-1 text-sm text-muted-foreground">
                    {__('Search, move')}{' '}
                    <Kbd>
                        <ArrowUp className="size-3" />
                    </Kbd>
                    <Kbd>
                        <ArrowDown className="size-3" />
                    </Kbd>
                    {__(', and press')}
                    <Kbd>⏎</Kbd>
                </p>
            </div>
            <Command
                className="rounded-xl border border-zinc-200 bg-white shadow-lg dark:border-zinc-800 dark:bg-zinc-900"
                shouldFilter={true}
            >
                <CommandInput
                    ref={commandInputRef}
                    placeholder={__('Search categories...')}
                    value={searchValue}
                    onValueChange={onSearchChange}
                    disabled={animationState !== 'idle' || disabled}
                />

                <CommandList className="max-h-64">
                    <CommandEmpty>{__('No categories found.')}</CommandEmpty>
                    <CommandGroup>
                        {sortedCategories.map((category) => {
                            const colorClasses = getCategoryColorClasses(
                                category.color,
                            );
                            return (
                                <CommandItem
                                    key={category.id}
                                    value={category.name}
                                    onSelect={() => onCategorySelect(category)}
                                    disabled={
                                        animationState !== 'idle' || disabled
                                    }
                                    className="group cursor-pointer gap-3 p-2"
                                >
                                    <div
                                        className={cn(
                                            'flex size-5 shrink-0 items-center justify-center rounded-full transition-transform duration-200 group-data-[selected=true]:scale-110',
                                            colorClasses.bg,
                                        )}
                                    >
                                        <CategoryIcon category={category} />
                                    </div>
                                    <span className="flex-1 truncate font-medium">
                                        {category.name}
                                    </span>
                                </CommandItem>
                            );
                        })}
                    </CommandGroup>
                </CommandList>
            </Command>

            <style>{`
                @keyframes categorizer-command-enter {
                    from {
                        opacity: 0;
                        transform: translateY(10px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }

                .animate-categorizer-command-enter {
                    animation: categorizer-command-enter 0.3s ease-out 0.1s forwards;
                    opacity: 0;
                }
            `}</style>
        </div>
    );
}
