import { applyColorScheme } from '@/hooks/use-chart-color-scheme';
import { cn } from '@/lib/utils';
import { type ChartColorScheme } from '@/types';
import { BookOpen } from 'lucide-react';
import {
    createContext,
    useContext,
    useEffect,
    type ComponentType,
    type ReactNode,
} from 'react';

/** Where the rules live: the page only previews what the document decides. */
export const DESIGN_SYSTEM_DOC_URL =
    'https://github.com/whisper-money/whisper-money/blob/main/docs/design-system.md';

export const CHART_COLOR_SCHEMES: ChartColorScheme[] = [
    'neutral',
    'colorful',
    'blue',
    'pink',
];

export interface DesignSystemDisplay {
    isDark: boolean;
    chartScheme: ChartColorScheme;
}

const DesignSystemDisplayContext = createContext<DesignSystemDisplay>({
    isDark: false,
    chartScheme: 'neutral',
});

export const DesignSystemDisplayProvider = DesignSystemDisplayContext.Provider;

export function useDesignSystemDisplay(): DesignSystemDisplay {
    return useContext(DesignSystemDisplayContext);
}

/**
 * Applies the toolbar's theme and chart palette to the document root, where
 * the app reads them (the `.dark` class and `data-chart-color`), so dialogs and
 * popovers portalled to <body> follow them too. Each effect puts back what it
 * found when it is undone, so leaving the page restores the visitor's own
 * settings: nothing is written to storage, cookies or the account. Does
 * nothing until the page has read the root (`display` is null until then).
 *
 * Known limit: the app's own `prefers-color-scheme` listener still runs, so an
 * OS theme switch while the page is open overrides the local toggle.
 */
export function useLocalDocumentDisplay(
    display: DesignSystemDisplay | null,
): void {
    const isDark = display?.isDark;
    const chartScheme = display?.chartScheme;

    useEffect(() => {
        if (isDark === undefined) {
            return;
        }

        const root = document.documentElement;
        const wasDark = root.classList.contains('dark');
        const previousColorScheme = root.style.colorScheme;

        root.classList.toggle('dark', isDark);
        root.style.colorScheme = isDark ? 'dark' : 'light';

        return () => {
            root.classList.toggle('dark', wasDark);
            root.style.colorScheme = previousColorScheme;
        };
    }, [isDark]);

    useEffect(() => {
        if (chartScheme === undefined) {
            return;
        }

        const previousScheme = readChartColorScheme();

        applyColorScheme(chartScheme);

        return () => applyColorScheme(previousScheme);
    }, [chartScheme]);
}

/** The chart theme <html> carries now; no attribute means neutral. */
export function readChartColorScheme(): ChartColorScheme {
    const scheme =
        document.documentElement.getAttribute('data-chart-color') ?? 'neutral';

    return isChartColorScheme(scheme) ? scheme : 'neutral';
}

export function isChartColorScheme(value: string): value is ChartColorScheme {
    return (CHART_COLOR_SCHEMES as string[]).includes(value);
}

export function DocLink({ anchor }: { anchor: string }) {
    return (
        <a
            href={`${DESIGN_SYSTEM_DOC_URL}#${anchor}`}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
        >
            <BookOpen className="size-3.5" />
            Rules
        </a>
    );
}

/** One preview of a section: its anchor, title and the component it renders. */
export interface PreviewEntry {
    id: string;
    title: string;
    className?: string;
    Component: ComponentType;
}

export function ShowcaseSection({
    id,
    title,
    children,
}: {
    id: string;
    title: string;
    children: ReactNode;
}) {
    return (
        <section
            id={id}
            aria-labelledby={`${id}-title`}
            className="flex scroll-mt-48 flex-col gap-6 lg:scroll-mt-24"
        >
            <div className="flex items-baseline justify-between gap-4 border-b pb-2">
                <h2
                    id={`${id}-title`}
                    className="text-xl font-semibold tracking-tight"
                >
                    {title}
                </h2>
                <DocLink anchor={id} />
            </div>
            {children}
        </section>
    );
}

/** One titled preview: an anchor in the side index and a link to its rules. */
export function Preview({
    id,
    title,
    className,
    children,
}: {
    id: string;
    title: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div
            id={id}
            className="flex scroll-mt-48 flex-col gap-3 lg:scroll-mt-24"
        >
            <div className="flex items-baseline justify-between gap-4">
                <h3 className="text-base font-medium">{title}</h3>
                <DocLink anchor={id} />
            </div>
            <div
                className={cn(
                    'flex flex-wrap items-center gap-4 rounded-lg border p-4',
                    className,
                )}
            >
                {children}
            </div>
        </div>
    );
}

/** A small caption under a specimen, naming the token or class it shows. */
export function Specimen({
    label,
    className,
    children,
}: {
    label: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <figure className={cn('flex flex-col gap-1.5', className)}>
            {children}
            <figcaption className="font-mono text-xs text-muted-foreground">
                {label}
            </figcaption>
        </figure>
    );
}
