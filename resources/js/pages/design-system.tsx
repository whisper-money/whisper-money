import { FINANCE_PREVIEWS } from '@/components/design-system/finance-patterns';
import { FOUNDATION_PREVIEWS } from '@/components/design-system/foundations';
import { PAGE_PATTERN_PREVIEWS } from '@/components/design-system/page-patterns';
import { PRIMITIVE_PREVIEWS } from '@/components/design-system/primitives';
import {
    CHART_COLOR_SCHEMES,
    DESIGN_SYSTEM_DOC_URL,
    DesignSystemDisplayProvider,
    isChartColorScheme,
    Preview,
    readChartColorScheme,
    ShowcaseSection,
    useLocalDocumentDisplay,
    type DesignSystemDisplay,
    type PreviewEntry,
} from '@/components/design-system/showcase';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { FixedPrivacyMode } from '@/contexts/privacy-mode-context';
import { Head } from '@inertiajs/react';
import { Eye, EyeOff, Moon, Sun } from 'lucide-react';
import { useEffect, useState } from 'react';

const SECTIONS: { id: string; title: string; previews: PreviewEntry[] }[] = [
    { id: 'foundations', title: 'Foundations', previews: FOUNDATION_PREVIEWS },
    { id: 'primitives', title: 'Primitives', previews: PRIMITIVE_PREVIEWS },
    {
        id: 'finance-patterns',
        title: 'Finance patterns',
        previews: FINANCE_PREVIEWS,
    },
    {
        id: 'page-patterns',
        title: 'Page patterns',
        previews: PAGE_PATTERN_PREVIEWS,
    },
];

/** What the server renders and the first client render repeats. */
const SERVER_DISPLAY: DesignSystemDisplay = {
    isDark: false,
    chartScheme: 'neutral',
};

function Toolbar({
    display,
    onChange,
    isMasked,
    onMaskedChange,
}: {
    display: DesignSystemDisplay;
    onChange: (display: DesignSystemDisplay) => void;
    isMasked: boolean;
    onMaskedChange: (isMasked: boolean) => void;
}) {
    const { isDark, chartScheme } = display;

    return (
        <div className="flex flex-wrap items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                aria-pressed={isDark}
                onClick={() => onChange({ ...display, isDark: !isDark })}
            >
                {isDark ? <Moon /> : <Sun />}
                {isDark ? 'Dark' : 'Light'}
            </Button>
            <Button
                variant="outline"
                size="sm"
                aria-pressed={isMasked}
                onClick={() => onMaskedChange(!isMasked)}
            >
                {isMasked ? <EyeOff /> : <Eye />}
                Privacy mode
            </Button>
            <ToggleGroup
                type="single"
                variant="outline"
                size="sm"
                aria-label="Chart theme"
                value={chartScheme}
                onValueChange={(value) =>
                    isChartColorScheme(value) &&
                    onChange({ ...display, chartScheme: value })
                }
            >
                {CHART_COLOR_SCHEMES.map((scheme) => (
                    <ToggleGroupItem
                        key={scheme}
                        value={scheme}
                        className="px-2.5"
                    >
                        {scheme}
                    </ToggleGroupItem>
                ))}
            </ToggleGroup>
        </div>
    );
}

function SectionLinks() {
    return (
        <nav
            aria-label="Design system sections (compact)"
            className="flex gap-4 overflow-x-auto text-sm lg:hidden"
        >
            {SECTIONS.map((section) => (
                <a
                    key={section.id}
                    href={`#${section.id}`}
                    className="shrink-0 text-muted-foreground hover:text-foreground"
                >
                    {section.title}
                </a>
            ))}
        </nav>
    );
}

function SideIndex() {
    return (
        <nav
            aria-label="Design system sections"
            className="sticky top-20 hidden max-h-[calc(100vh-6rem)] w-48 shrink-0 flex-col gap-4 overflow-y-auto text-sm lg:flex"
        >
            {SECTIONS.map((section) => (
                <div key={section.id} className="flex flex-col gap-1.5">
                    <a href={`#${section.id}`} className="font-medium">
                        {section.title}
                    </a>
                    {section.previews.map((preview) => (
                        <a
                            key={preview.id}
                            href={`#${preview.id}`}
                            className="pl-3 text-muted-foreground hover:text-foreground"
                        >
                            {preview.title}
                        </a>
                    ))}
                </div>
            ))}
        </nav>
    );
}

export default function DesignSystem() {
    // Null until the page has read what <html> shows (theme and chart palette
    // of the visitor), so the server and the first client render agree.
    const [display, setDisplay] = useState<DesignSystemDisplay | null>(null);
    const [isMasked, setIsMasked] = useState(false);

    useEffect(() => {
        setDisplay({
            isDark: document.documentElement.classList.contains('dark'),
            chartScheme: readChartColorScheme(),
        });
    }, []);

    useLocalDocumentDisplay(display);

    const shownDisplay = display ?? SERVER_DISPLAY;

    return (
        <>
            <Head title="Design system">
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <DesignSystemDisplayProvider value={shownDisplay}>
                <FixedPrivacyMode enabled={isMasked}>
                    <div className="min-h-screen bg-background text-foreground">
                        <header className="sticky top-0 z-20 border-b bg-background/95 backdrop-blur">
                            <div className="mx-auto flex max-w-page flex-col gap-3 px-4 py-3 sm:px-6">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="flex items-baseline gap-3">
                                        <h1 className="text-xl font-semibold tracking-tight">
                                            Whisper Money design system
                                        </h1>
                                        <a
                                            href={DESIGN_SYSTEM_DOC_URL}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="hidden text-sm text-muted-foreground underline-offset-4 hover:underline sm:inline"
                                        >
                                            docs/design-system.md
                                        </a>
                                    </div>
                                    <Toolbar
                                        display={shownDisplay}
                                        onChange={setDisplay}
                                        isMasked={isMasked}
                                        onMaskedChange={setIsMasked}
                                    />
                                </div>
                                <SectionLinks />
                            </div>
                        </header>
                        <div className="mx-auto flex max-w-page gap-10 px-4 py-8 sm:px-6">
                            <SideIndex />
                            <main className="flex min-w-0 flex-1 flex-col gap-16">
                                {SECTIONS.map((section) => (
                                    <ShowcaseSection
                                        key={section.id}
                                        id={section.id}
                                        title={section.title}
                                    >
                                        {section.previews.map(
                                            ({
                                                id,
                                                title,
                                                className,
                                                Component,
                                            }) => (
                                                <Preview
                                                    key={id}
                                                    id={id}
                                                    title={title}
                                                    className={className}
                                                >
                                                    <Component />
                                                </Preview>
                                            ),
                                        )}
                                    </ShowcaseSection>
                                ))}
                            </main>
                        </div>
                    </div>
                </FixedPrivacyMode>
            </DesignSystemDisplayProvider>
        </>
    );
}
