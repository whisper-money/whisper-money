import {
    CHART_COLOR_SCHEMES,
    Specimen,
    useDesignSystemDisplay,
    type PreviewEntry,
} from '@/components/design-system/showcase';
import { cn } from '@/lib/utils';

const COLOR_TOKENS = [
    'background',
    'foreground',
    'card',
    'popover',
    'primary',
    'primary-foreground',
    'secondary',
    'muted',
    'muted-foreground',
    'accent',
    'border',
    'input',
    'ring',
    'success',
    'warning',
    'warning-foreground',
    'destructive',
    'destructive-foreground',
    'sidebar',
];

const STREAK_TOKENS = [
    'streak-fill',
    'streak-border',
    'streak-track',
    'streak-from',
    'streak-to',
    'streak-ink',
];

const CHART_SLOTS = Array.from({ length: 10 }, (_, index) => index + 1);

function Swatch({ token }: { token: string }) {
    return (
        <Specimen label={`--${token}`} className="w-28">
            <div
                className="h-12 rounded-md border"
                style={{ background: `var(--${token})` }}
            />
        </Specimen>
    );
}

function ColorTokens() {
    return (
        <div className="flex flex-col gap-6">
            <div className="flex flex-wrap gap-4">
                {COLOR_TOKENS.map((token) => (
                    <Swatch key={token} token={token} />
                ))}
            </div>
            <div className="flex flex-wrap gap-4">
                {STREAK_TOKENS.map((token) => (
                    <Swatch key={token} token={token} />
                ))}
            </div>
        </div>
    );
}

/**
 * The four palettes side by side. Each block sets its own `data-chart-color`
 * (and `.dark`, which the dark palettes are keyed on) so it shows its palette
 * whatever the toolbar picked for the rest of the page.
 */
function ChartPalettes() {
    const { isDark, chartScheme } = useDesignSystemDisplay();

    return (
        <div className="grid w-full gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {CHART_COLOR_SCHEMES.map((scheme) => (
                <div
                    key={scheme}
                    data-chart-color={scheme}
                    className={cn(
                        'flex flex-col gap-2 rounded-md p-3',
                        isDark && 'dark',
                        scheme === chartScheme && 'ring-2 ring-ring',
                    )}
                >
                    <span className="font-mono text-xs">{scheme}</span>
                    <div className="flex">
                        {CHART_SLOTS.map((slot) => (
                            <div
                                key={slot}
                                title={`--chart-${slot}`}
                                className="h-10 flex-1 first:rounded-l-md last:rounded-r-md"
                                style={{ background: `var(--chart-${slot})` }}
                            />
                        ))}
                    </div>
                    <span className="font-mono text-xs text-muted-foreground">
                        --chart-1 … --chart-10
                    </span>
                </div>
            ))}
        </div>
    );
}

const FONT_SPECIMENS = [
    { label: 'font-sans · IBM Plex Sans', className: 'font-sans text-2xl' },
    { label: 'font-mono · Google Sans Code', className: 'font-mono text-2xl' },
    {
        label: 'font-heading · IBM Plex Serif italic',
        className: 'font-heading text-3xl',
    },
];

const TEXT_SIZES = [
    'text-xs',
    'text-sm',
    'text-base',
    'text-lg',
    'text-xl',
    'text-2xl',
    'text-3xl',
    'text-4xl',
];

function Typography() {
    return (
        <div className="flex w-full flex-col gap-6">
            {FONT_SPECIMENS.map(({ label, className }) => (
                <Specimen key={label} label={label}>
                    <span className={className}>Your money, your data</span>
                </Specimen>
            ))}
            <div className="flex flex-col gap-2">
                {TEXT_SIZES.map((size) => (
                    <Specimen key={size} label={size}>
                        <span className={size}>1.234,56 €</span>
                    </Specimen>
                ))}
            </div>
        </div>
    );
}

const RADII = ['rounded-sm', 'rounded-md', 'rounded-lg', 'rounded-xl'];
const SHADOWS = ['shadow-xs', 'shadow-sm', 'shadow-md', 'shadow-lg'];

function BoxScale({ classes, base }: { classes: string[]; base: string }) {
    return classes.map((className) => (
        <Specimen key={className} label={className}>
            <div className={cn('size-20 bg-card', base, className)} />
        </Specimen>
    ));
}

function Radius() {
    return <BoxScale classes={RADII} base="border" />;
}

function Shadows() {
    return <BoxScale classes={SHADOWS} base="rounded-lg border" />;
}

function PageWidth() {
    return (
        <Specimen label="max-w-page · 80rem" className="w-full">
            <div className="mx-auto h-8 w-full max-w-page rounded-md border border-dashed bg-muted" />
        </Specimen>
    );
}

export const FOUNDATION_PREVIEWS: PreviewEntry[] = [
    { id: 'color-tokens', title: 'Color tokens', Component: ColorTokens },
    { id: 'chart-palettes', title: 'Chart palettes', Component: ChartPalettes },
    { id: 'typography', title: 'Typography', Component: Typography },
    { id: 'radius', title: 'Radius', Component: Radius },
    { id: 'shadows', title: 'Shadows', Component: Shadows },
    { id: 'page-width', title: 'Page width', Component: PageWidth },
];
