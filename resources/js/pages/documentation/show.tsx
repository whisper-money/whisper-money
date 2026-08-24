import DocArticle from '@/components/documentation/doc-article';
import DocLanguageMenu from '@/components/documentation/doc-language-menu';
import DocNav from '@/components/documentation/doc-nav';
import DocSearch from '@/components/documentation/doc-search';
import DocToc from '@/components/documentation/doc-toc';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import { dashboard, login } from '@/routes';
import { type SharedData } from '@/types';
import {
    type DocumentationDocument,
    type DocumentationLanguage,
    type DocumentationNavItem,
    type DocumentationNeighbour,
    type DocumentationSearchEntry,
} from '@/types/documentation';
import { __ } from '@/utils/i18n';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftIcon,
    ArrowRightIcon,
    BirdIcon,
    ChevronDownIcon,
    Github,
    MenuIcon,
} from 'lucide-react';
import { useState } from 'react';

type Props = {
    document: DocumentationDocument;
    navigation: DocumentationNavItem[];
    searchIndex: DocumentationSearchEntry[];
    neighbours: {
        previous: DocumentationNeighbour | null;
        next: DocumentationNeighbour | null;
    };
    languages: DocumentationLanguage[];
};

const MUTED = 'text-[#706f6c] dark:text-[#A1A09A]';
const BORDER = 'border-[#e3e3e0] dark:border-[#3E3E3A]';

function PageLink({
    page,
    direction,
}: {
    page: DocumentationNeighbour;
    direction: 'previous' | 'next';
}) {
    const isNext = direction === 'next';

    return (
        <Link
            href={page.url}
            className={cn(
                'flex flex-1 items-center gap-3 rounded-xl border p-4 no-underline transition-colors hover:bg-black/[0.02] dark:hover:bg-white/[0.03]',
                BORDER,
                isNext && 'flex-row-reverse text-right',
            )}
        >
            {isNext ? (
                <ArrowRightIcon
                    className={cn('size-4 shrink-0', MUTED)}
                    aria-hidden="true"
                />
            ) : (
                <ArrowLeftIcon
                    className={cn('size-4 shrink-0', MUTED)}
                    aria-hidden="true"
                />
            )}
            <span className="flex min-w-0 flex-col gap-0.5">
                <span className={cn('text-xs', MUTED)}>
                    {isNext ? __('Next') : __('Previous')}
                </span>
                <span className="truncate text-sm font-medium">
                    {page.title}
                </span>
            </span>
        </Link>
    );
}

export default function DocumentationShow({
    document: doc,
    navigation,
    searchIndex,
    neighbours,
    languages,
}: Props) {
    const { appUrl, auth } = usePage<SharedData>().props;
    const [menuOpen, setMenuOpen] = useState(false);
    const canonical = `${appUrl}/documentation/${doc.slug}?lang=${doc.locale}`;

    return (
        <>
            <Head title={doc.title}>
                <meta name="description" content={doc.description} />
                <link rel="canonical" href={canonical} />
                <link
                    rel="alternate"
                    type="text/markdown"
                    href={`${appUrl}${doc.markdownUrl}`}
                />
                <meta name="robots" content="index, follow" />
                <meta property="og:title" content={doc.title} />
                <meta property="og:description" content={doc.description} />
                <meta property="og:type" content="article" />
                <meta property="og:url" content={canonical} />
            </Head>

            <div className="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <header
                    className={cn(
                        'sticky top-0 z-40 border-b bg-[#FDFDFC]/85 backdrop-blur-md dark:bg-[#0a0a0a]/85',
                        BORDER,
                    )}
                >
                    <div className="mx-auto flex h-16 max-w-[1440px] items-center gap-3 px-4 sm:px-6">
                        <button
                            type="button"
                            onClick={() => setMenuOpen(true)}
                            aria-label={__('Open documentation menu')}
                            className="-ml-2 flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-lg transition-colors hover:bg-black/5 lg:hidden dark:hover:bg-white/10"
                        >
                            <MenuIcon className="size-5" aria-hidden="true" />
                        </button>

                        <Link
                            href="/"
                            className="flex shrink-0 items-center gap-2.5 font-mono no-underline lg:w-60"
                        >
                            <BirdIcon
                                className="size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <span className="text-sm font-medium whitespace-nowrap">
                                Whisper Money
                            </span>
                            <span
                                className={cn(
                                    'hidden text-sm whitespace-nowrap sm:inline',
                                    MUTED,
                                )}
                            >
                                / {__('Documentation')}
                            </span>
                        </Link>

                        <div className="flex flex-1 justify-end sm:justify-center">
                            <DocSearch index={searchIndex} />
                        </div>

                        <div className="hidden shrink-0 items-center gap-3 md:flex">
                            <a
                                href="https://github.com/whisper-money/whisper-money"
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label={__('Github')}
                                className={cn(
                                    'flex size-9 items-center justify-center rounded-lg transition-colors hover:bg-black/5 dark:hover:bg-white/10',
                                    MUTED,
                                )}
                            >
                                <Github className="size-5" aria-hidden="true" />
                            </a>
                            <Link
                                href={auth.user ? dashboard() : login()}
                                className="flex h-9 items-center rounded-lg bg-[#1b1b18] px-4 text-sm font-medium text-white no-underline transition-opacity hover:opacity-90 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                            >
                                {auth.user ? __('Dashboard') : __('Log in')}
                            </Link>
                        </div>
                    </div>
                </header>

                <div className="mx-auto flex max-w-[1440px] gap-8 px-4 sm:px-6">
                    <aside className="sticky top-16 hidden h-[calc(100vh-4rem)] w-60 shrink-0 flex-col overflow-hidden lg:flex">
                        <nav
                            className="min-h-0 flex-1 overflow-y-auto py-8"
                            aria-label={__('Documentation')}
                        >
                            <DocNav items={navigation} />
                        </nav>
                        <div className={cn('shrink-0 border-t py-3', BORDER)}>
                            <DocLanguageMenu languages={languages} />
                        </div>
                    </aside>

                    <main className="min-w-0 flex-1 py-8 lg:py-10">
                        <Collapsible
                            className={cn(
                                'mb-8 rounded-xl border xl:hidden',
                                BORDER,
                            )}
                        >
                            <CollapsibleTrigger className="flex w-full cursor-pointer items-center gap-2 px-4 py-3 text-sm font-medium">
                                <ChevronDownIcon
                                    className="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span className="flex-1 text-left">
                                    {__('On this page')}
                                </span>
                                <span className={cn('text-xs', MUTED)}>
                                    {doc.headings.length}
                                </span>
                            </CollapsibleTrigger>
                            <CollapsibleContent className="px-4 pb-4">
                                <DocToc headings={doc.headings} />
                            </CollapsibleContent>
                        </Collapsible>

                        <DocArticle html={doc.html} />

                        {(neighbours.previous || neighbours.next) && (
                            <nav
                                className={cn(
                                    'mt-12 flex max-w-3xl flex-col gap-4 border-t pt-8 sm:flex-row',
                                    BORDER,
                                )}
                                aria-label={__('Documentation')}
                            >
                                {neighbours.previous && (
                                    <PageLink
                                        page={neighbours.previous}
                                        direction="previous"
                                    />
                                )}
                                {neighbours.next && (
                                    <PageLink
                                        page={neighbours.next}
                                        direction="next"
                                    />
                                )}
                            </nav>
                        )}
                    </main>

                    <aside className="sticky top-16 hidden h-[calc(100vh-4rem)] w-56 shrink-0 overflow-y-auto py-10 xl:block">
                        <p
                            className={cn(
                                'mb-3 text-xs font-semibold tracking-[0.12em] uppercase',
                                MUTED,
                            )}
                        >
                            {__('On this page')}
                        </p>
                        <DocToc headings={doc.headings} />
                    </aside>
                </div>

                <Sheet open={menuOpen} onOpenChange={setMenuOpen}>
                    <SheetContent side="left" className="w-80 gap-0 p-0">
                        <SheetHeader
                            className={cn(
                                'border-b px-6 py-5 text-left',
                                BORDER,
                            )}
                        >
                            <SheetTitle>{__('Documentation')}</SheetTitle>
                        </SheetHeader>

                        <nav
                            className="flex-1 overflow-y-auto px-4 py-4"
                            aria-label={__('Documentation')}
                        >
                            <DocNav
                                items={navigation}
                                onNavigate={() => setMenuOpen(false)}
                            />
                        </nav>

                        <div className={cn('border-t px-4 py-3', BORDER)}>
                            <DocLanguageMenu
                                languages={languages}
                                onNavigate={() => setMenuOpen(false)}
                            />
                        </div>
                    </SheetContent>
                </Sheet>
            </div>
        </>
    );
}
