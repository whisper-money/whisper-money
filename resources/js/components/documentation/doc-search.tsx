import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Kbd } from '@/components/ui/kbd';
import { type DocumentationSearchEntry } from '@/types/documentation';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { FileTextIcon, HashIcon, SearchIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

type Props = {
    index: DocumentationSearchEntry[];
};

/**
 * Accents are how the Spanish pages are written but not how they are typed, so
 * "categorias" has to find "Categorías".
 */
function fold(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '');
}

/**
 * What the reader typed has to actually appear in the result. cmdk scores
 * subsequences by default, which in a page of headings means "saldos" matching
 * "Rutina semanal recomendada".
 */
function score(value: string, search: string): number {
    return fold(value).includes(fold(search)) ? 1 : 0;
}

/**
 * Search over every page and every section heading in the documentation. The
 * whole index arrives with the page, so answering never needs a round trip.
 */
export default function DocSearch({ index }: Props) {
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const openOnShortcut = (event: KeyboardEvent) => {
            if (event.key.toLowerCase() !== 'k') {
                return;
            }

            if (!event.metaKey && !event.ctrlKey) {
                return;
            }

            event.preventDefault();
            setOpen((previous) => !previous);
        };

        window.addEventListener('keydown', openOnShortcut);

        return () => window.removeEventListener('keydown', openOnShortcut);
    }, []);

    const visit = (url: string) => {
        setOpen(false);
        router.visit(url);
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="hidden h-9 w-full max-w-md cursor-pointer items-center gap-2 rounded-lg border border-[#e3e3e0] bg-white pr-2 pl-3 text-sm text-[#706f6c] shadow-xs transition-colors hover:border-[#706f6c] sm:flex dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#A1A09A] dark:hover:border-[#A1A09A]"
            >
                <SearchIcon className="size-4 shrink-0" aria-hidden="true" />
                <span className="flex-1 truncate text-left">
                    {__('Search the documentation')}
                </span>
                <Kbd className="font-mono">⌘K</Kbd>
            </button>

            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label={__('Search the documentation')}
                className="flex size-11 cursor-pointer items-center justify-center rounded-lg text-[#1b1b18] transition-colors hover:bg-black/5 sm:hidden dark:text-[#EDEDEC] dark:hover:bg-white/10"
            >
                <SearchIcon className="size-5" aria-hidden="true" />
            </button>

            <CommandDialog
                open={open}
                onOpenChange={setOpen}
                title={__('Search the documentation')}
                description={__('Search pages and sections.')}
                filter={score}
            >
                <CommandInput
                    placeholder={__('Search the documentation')}
                    autoFocus
                />
                <CommandList className="max-h-[60vh]">
                    <CommandEmpty>{__('No results found.')}</CommandEmpty>

                    <CommandGroup heading={__('Pages')}>
                        {index.map((page) => (
                            <CommandItem
                                key={page.slug}
                                value={page.title}
                                onSelect={() => visit(page.url)}
                                className="cursor-pointer gap-3"
                            >
                                <FileTextIcon
                                    className="text-[#706f6c] dark:text-[#A1A09A]"
                                    aria-hidden="true"
                                />
                                <span>{page.title}</span>
                            </CommandItem>
                        ))}
                    </CommandGroup>

                    <CommandGroup heading={__('Sections')}>
                        {index.flatMap((page) =>
                            page.headings.map((heading) => (
                                <CommandItem
                                    key={`${page.slug}-${heading.id}`}
                                    value={`${heading.title} ${page.title}`}
                                    onSelect={() =>
                                        visit(`${page.url}#${heading.id}`)
                                    }
                                    className="cursor-pointer gap-3"
                                >
                                    <HashIcon
                                        className="text-[#706f6c] dark:text-[#A1A09A]"
                                        aria-hidden="true"
                                    />
                                    <span className="flex flex-col gap-0.5">
                                        <span>{heading.title}</span>
                                        <span className="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                                            {page.title}
                                        </span>
                                    </span>
                                </CommandItem>
                            )),
                        )}
                    </CommandGroup>
                </CommandList>
            </CommandDialog>
        </>
    );
}
