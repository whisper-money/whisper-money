import { FINANCE_PREVIEWS } from '@/components/design-system/finance-patterns';
import { FOUNDATION_PREVIEWS } from '@/components/design-system/foundations';
import { PAGE_PATTERN_PREVIEWS } from '@/components/design-system/page-patterns';
import { PRIMITIVE_PREVIEWS } from '@/components/design-system/primitives';
import {
    CHART_COLOR_SCHEMES,
    DESIGN_SYSTEM_DOC_URL,
    DesignSystemDisplayProvider,
    Preview,
    ShowcaseSection,
    useLocalDocumentDisplay,
    type PreviewEntry,
} from '@/components/design-system/showcase';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { FixedPrivacyMode } from '@/contexts/privacy-mode-context';
import { type ChartColorScheme } from '@/types';
import { Head } from '@inertiajs/react';
import { Eye, EyeOff, Moon, Sun } from 'lucide-react';
import { useMemo, useState } from 'react';

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

function isChartColorScheme(value: string): value is ChartColorScheme {
    return (CHART_COLOR_SCHEMES as string[]).includes(value);
}

/** Starts from what the visitor's page already shows, read off the root. */
function initialDisplay() {
    if (typeof document === 'undefined') {
        return { isDark: false, chartScheme: 'colorful' as ChartColorScheme };
    }

    const root = document.documentElement;
    const scheme = root.getAttribute('data-chart-color') ?? 'neutral';

    return {
        isDark: root.classList.contains('dark'),
        chartScheme: isChartColorScheme(scheme) ? scheme : 'colorful',
    };
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
    const [initial] = useState(initialDisplay);
    const [isDark, setIsDark] = useState(initial.isDark);
    const [chartScheme, setChartScheme] = useState(initial.chartScheme);
    const [isMasked, setIsMasked] = useState(false);
    const display = useMemo(
        () => ({ isDark, chartScheme }),
        [isDark, chartScheme],
    );

    useLocalDocumentDisplay(display);

    return (
        <>
            <Head title="Design system">
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <DesignSystemDisplayProvider value={display}>
                <FixedPrivacyMode enabled={isMasked}>
                    <div className="min-h-screen bg-background text-foreground">
                        <header className="sticky top-0 z-20 border-b bg-background/95 backdrop-blur">
                            <div className="mx-auto flex max-w-page flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
                                <div className="flex items-baseline gap-3">
                                    <h1 className="font-heading text-2xl">
                                        Whisper Money design system
                                    </h1>
                                    <a
                                        href={DESIGN_SYSTEM_DOC_URL}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                                    >
                                        docs/design-system.md
                                    </a>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        aria-pressed={isDark}
                                        onClick={() => setIsDark(!isDark)}
                                    >
                                        {isDark ? <Moon /> : <Sun />}
                                        {isDark ? 'Dark' : 'Light'}
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        aria-pressed={isMasked}
                                        onClick={() => setIsMasked(!isMasked)}
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
                                            setChartScheme(value)
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
