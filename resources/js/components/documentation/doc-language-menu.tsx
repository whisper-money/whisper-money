import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { type DocumentationLanguage } from '@/types/documentation';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { CheckIcon, ChevronDownIcon, GlobeIcon } from 'lucide-react';

type Props = {
    languages: DocumentationLanguage[];
    onNavigate?: () => void;
};

/**
 * Switches the language of the page being read. A menu rather than a row of
 * pills, because the list grows: each language is one entry, and the same page
 * in that language is one link away.
 */
export default function DocLanguageMenu({ languages, onNavigate }: Props) {
    const current =
        languages.find((language) => language.active) ?? languages[0];

    if (!current) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                className="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-lg px-3 text-sm text-[#706f6c] transition-colors hover:bg-black/[0.03] hover:text-[#1b1b18] lg:min-h-9 dark:text-[#A1A09A] dark:hover:bg-white/[0.04] dark:hover:text-[#EDEDEC]"
                aria-label={__('Language')}
            >
                <GlobeIcon className="size-4 shrink-0" aria-hidden="true" />
                <span className="flex-1 truncate text-left">
                    {current.label}
                </span>
                <ChevronDownIcon
                    className="size-3.5 shrink-0 opacity-60"
                    aria-hidden="true"
                />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-48">
                {languages.map((language) => (
                    <DropdownMenuItem key={language.locale} asChild>
                        <Link
                            href={language.url}
                            onClick={onNavigate}
                            className="block w-full cursor-pointer"
                            aria-current={language.active ? 'true' : undefined}
                        >
                            <CheckIcon
                                className={cn(
                                    'mr-2 size-4',
                                    !language.active && 'opacity-0',
                                )}
                                aria-hidden="true"
                            />
                            {language.label}
                        </Link>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
