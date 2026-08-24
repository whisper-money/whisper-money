import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';
import { type DocumentationNavItem } from '@/types/documentation';
import { Link } from '@inertiajs/react';
import { ChevronRightIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

type Props = {
    items: DocumentationNavItem[];
    onNavigate?: () => void;
};

/**
 * Opens with the branch the current page is in, and stays open once a reader has
 * opened it: navigating never closes what they went looking through.
 */
function useOpenState(expanded: boolean) {
    const [open, setOpen] = useState(expanded);

    useEffect(() => {
        if (expanded) {
            setOpen(true);
        }
    }, [expanded]);

    return [open, setOpen] as const;
}

function Chevron({ open }: { open: boolean }) {
    return (
        <ChevronRightIcon
            className={cn(
                'size-3.5 shrink-0 transition-transform duration-200',
                open && 'rotate-90',
            )}
            aria-hidden="true"
        />
    );
}

function PageItem({
    item,
    onNavigate,
}: {
    item: DocumentationNavItem;
    onNavigate?: () => void;
}) {
    const [open, setOpen] = useOpenState(item.expanded);
    const hasChildren = item.children.length > 0;

    return (
        <div>
            <div className="flex items-center gap-1">
                <Link
                    href={item.url ?? '#'}
                    onClick={onNavigate}
                    aria-current={item.active ? 'page' : undefined}
                    className={cn(
                        'min-h-11 flex-1 rounded-lg px-3 py-2 text-sm transition-colors lg:min-h-0',
                        item.active
                            ? 'bg-[#1b1b18] font-medium text-white dark:bg-[#EDEDEC] dark:text-[#1b1b18]'
                            : 'text-[#706f6c] hover:bg-black/5 hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:bg-white/10 dark:hover:text-[#EDEDEC]',
                    )}
                >
                    {item.title}
                </Link>
                {hasChildren && (
                    <button
                        type="button"
                        onClick={() => setOpen(!open)}
                        aria-expanded={open}
                        aria-label={item.title}
                        className="flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg text-[#706f6c] transition-colors hover:bg-black/5 hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:bg-white/10 dark:hover:text-[#EDEDEC]"
                    >
                        <Chevron open={open} />
                    </button>
                )}
            </div>

            {hasChildren && open && (
                <div className="mt-1 ml-4 flex flex-col gap-0.5 border-l border-[#e3e3e0] pl-2 dark:border-[#3E3E3A]">
                    <DocNav items={item.children} onNavigate={onNavigate} />
                </div>
            )}
        </div>
    );
}

function SectionItem({
    item,
    onNavigate,
}: {
    item: DocumentationNavItem;
    onNavigate?: () => void;
}) {
    const [open, setOpen] = useOpenState(true);

    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            className="mt-4 first:mt-0"
        >
            <CollapsibleTrigger className="flex w-full cursor-pointer items-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-semibold tracking-[0.12em] text-[#706f6c] uppercase transition-colors hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]">
                <Chevron open={open} />
                <span>{item.title}</span>
            </CollapsibleTrigger>
            <CollapsibleContent className="mt-1 flex flex-col gap-0.5">
                <DocNav items={item.children} onNavigate={onNavigate} />
            </CollapsibleContent>
        </Collapsible>
    );
}

/**
 * The documentation tree. Recursive, so the depth of the sidebar is whatever
 * depth the files are nested to.
 */
export default function DocNav({ items, onNavigate }: Props) {
    return (
        <>
            {items.map((item) =>
                item.type === 'section' ? (
                    <SectionItem
                        key={item.key}
                        item={item}
                        onNavigate={onNavigate}
                    />
                ) : (
                    <PageItem
                        key={item.key}
                        item={item}
                        onNavigate={onNavigate}
                    />
                ),
            )}
        </>
    );
}
