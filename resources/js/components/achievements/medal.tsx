import { cn } from '@/lib/utils';
import { type AchievementRarity } from '@/types';
import {
    ArrowUpRightIcon,
    CalendarCheckIcon,
    ChartLineIcon,
    CircleCheckIcon,
    CoinsIcon,
    FlagIcon,
    FlameIcon,
    LandmarkIcon,
    Layers2Icon,
    LinkIcon,
    PercentIcon,
    PiggyBankIcon,
    PlusIcon,
    ReceiptIcon,
    ShieldCheckIcon,
    TagsIcon,
    TargetIcon,
    TrendingUpIcon,
    ZapIcon,
    type LucideIcon,
} from 'lucide-react';
import { useId } from 'react';

/**
 * The medal.
 *
 * A tier is a metal: copper, steel, gold, and obsidian for the top rung. Metal
 * is metal in either theme, so nothing here swaps with dark mode. The one thing
 * that does is the tier label written on paper beside the medal, and those four
 * colours live as `--medal-*` tokens in app.css rather than here.
 *
 * Shape still escalates alongside the metal — a plain disc, a disc inside a
 * ring, a serrated crown, a crown with an inner bezel — so the order survives
 * greyscale and colour blindness. Colour is the second reading, never the only
 * one.
 *
 * Obsidian is the deliberate break in the ladder: it is the only medal whose
 * pictogram inverts, gold on near-black instead of ink on metal, which is what
 * makes the rarest rung stand out on a wall of pale ones.
 *
 * A medal still to come is a dashed empty ring: it says "not yet", never
 * "failed", and it carries no pictogram to guess the name from.
 */
const ICONS: Record<string, LucideIcon> = {
    'arrow-up-right': ArrowUpRightIcon,
    'calendar-check': CalendarCheckIcon,
    'chart-line': ChartLineIcon,
    'circle-check': CircleCheckIcon,
    coins: CoinsIcon,
    flag: FlagIcon,
    flame: FlameIcon,
    landmark: LandmarkIcon,
    layers: Layers2Icon,
    link: LinkIcon,
    percent: PercentIcon,
    'piggy-bank': PiggyBankIcon,
    plus: PlusIcon,
    receipt: ReceiptIcon,
    'shield-check': ShieldCheckIcon,
    tags: TagsIcon,
    target: TargetIcon,
    'trending-up': TrendingUpIcon,
    zap: ZapIcon,
};

/**
 * One metal, as five stops in the order light crosses a struck disc: the
 * highlight it catches, the lit face, the core hue, the shadow, and the light
 * bouncing back off whatever it is lying on. `rim` is the troquelled edge,
 * `ink` the engraved pictogram.
 *
 * The hues alternate warm, cool, warm, dark on purpose: four medals in a row
 * are a ladder, not four shades of the same tan.
 */
type Metal = {
    hi: string;
    light: string;
    core: string;
    shadow: string;
    bounce: string;
    rim: string;
    ink: string;
    sheen: number;
    /** A crown struck in another metal, which only obsidian has. */
    bezel?: Metal;
};

const COPPER: Metal = {
    hi: 'oklch(0.81 0.085 50)',
    light: 'oklch(0.71 0.12 43)',
    core: 'oklch(0.60 0.13 38)',
    shadow: 'oklch(0.44 0.10 34)',
    bounce: 'oklch(0.58 0.12 45)',
    rim: 'oklch(0.38 0.09 34)',
    ink: 'oklch(0.26 0.06 36)',
    sheen: 0.34,
};

const STEEL: Metal = {
    hi: 'oklch(0.94 0.008 250)',
    light: 'oklch(0.85 0.011 252)',
    core: 'oklch(0.73 0.014 254)',
    shadow: 'oklch(0.55 0.017 257)',
    bounce: 'oklch(0.79 0.012 250)',
    // The dark struck edge is load-bearing: without it a face this pale
    // dissolves into white paper.
    rim: 'oklch(0.47 0.017 256)',
    ink: 'oklch(0.28 0.012 255)',
    sheen: 0.4,
};

const GOLD: Metal = {
    hi: 'oklch(0.95 0.07 97)',
    light: 'oklch(0.88 0.13 92)',
    core: 'oklch(0.79 0.155 86)',
    shadow: 'oklch(0.60 0.135 72)',
    bounce: 'oklch(0.85 0.14 88)',
    rim: 'oklch(0.51 0.12 68)',
    ink: 'oklch(0.30 0.07 70)',
    sheen: 0.42,
};

const OBSIDIAN: Metal = {
    hi: 'oklch(0.42 0.020 262)',
    light: 'oklch(0.33 0.018 264)',
    core: 'oklch(0.255 0.015 266)',
    shadow: 'oklch(0.165 0.012 268)',
    bounce: 'oklch(0.34 0.018 262)',
    rim: 'oklch(0.12 0.010 268)',
    ink: 'oklch(0.87 0.12 92)',
    sheen: 0.14,
    bezel: GOLD,
};

const METALS: Record<AchievementRarity, Metal> = {
    common: COPPER,
    uncommon: STEEL,
    rare: GOLD,
    epic: OBSIDIAN,
};

/** The card border an epic medal earns: the gold of its own crown. */
export const EPIC_EDGE = GOLD.core;

/**
 * Radii on the 48-unit grid, and the pictogram each tier can carry: a crown
 * eats into the face, so the glyph shrinks as the rim grows.
 */
const GEOMETRY: Record<
    AchievementRarity,
    { face: number; glyph: number; crown: boolean; ring: boolean }
> = {
    common: { face: 20, glyph: 21, crown: false, ring: false },
    uncommon: { face: 18.5, glyph: 19, crown: false, ring: true },
    rare: { face: 17.5, glyph: 16.5, crown: true, ring: false },
    epic: { face: 18, glyph: 15, crown: true, ring: false },
};

/** A star polygon with `points` teeth, used for the two top tiers. */
function rosette(points: number, outer: number, inner: number): string {
    return Array.from({ length: points * 2 }, (_, index) => {
        const angle = (Math.PI * index) / points - Math.PI / 2;
        const radius = index % 2 === 0 ? outer : inner;

        return `${(24 + radius * Math.cos(angle)).toFixed(2)},${(24 + radius * Math.sin(angle)).toFixed(2)}`;
    }).join(' ');
}

const CROWN = rosette(18, 23.5, 20.5);

/**
 * The face ramp, the bevelled inner edge and the single specular sweep, all
 * three keyed to this medal so a page full of them cannot collide.
 */
function MetalDefs({ id, metal }: { id: string; metal: Metal }) {
    return (
        <>
            <linearGradient id={`${id}-face`} x1="0.12" y1="0" x2="0.82" y2="1">
                <stop offset="0" stopColor={metal.hi} />
                <stop offset="0.28" stopColor={metal.light} />
                <stop offset="0.52" stopColor={metal.core} />
                <stop offset="0.78" stopColor={metal.shadow} />
                <stop offset="1" stopColor={metal.bounce} />
            </linearGradient>
            <linearGradient id={`${id}-bevel`} x1="0.2" y1="0" x2="0.78" y2="1">
                <stop offset="0" stopColor="#fff" stopOpacity="0.6" />
                <stop offset="0.4" stopColor="#fff" stopOpacity="0" />
                <stop offset="0.62" stopColor="#000" stopOpacity="0" />
                <stop offset="1" stopColor="#000" stopOpacity="0.34" />
            </linearGradient>
            <radialGradient id={`${id}-sheen`} cx="0.5" cy="0.5" r="0.5">
                <stop offset="0" stopColor="#fff" stopOpacity={metal.sheen} />
                <stop offset="1" stopColor="#fff" stopOpacity="0" />
            </radialGradient>
        </>
    );
}

/**
 * The pictogram, stamped rather than painted: a lit copy sits a fraction of a
 * pixel below the ink, which is the whole trick behind an engraved edge.
 *
 * Both copies are centred with the `translate` property written out in full,
 * never with Tailwind's `-translate-x-1/2`: those utilities compile to
 * `translate` too, so a `translate` of our own silently drops the half-width
 * they were holding and the lit copy slides off sideways.
 */
function Engraving({
    Icon,
    size,
    metal,
}: {
    Icon: LucideIcon;
    size: number;
    metal: Metal;
}) {
    const lit =
        metal === OBSIDIAN ? 'rgba(0,0,0,0.55)' : 'rgba(255,255,255,0.62)';
    const offset = Math.max(0.4, size / 26);
    const box = {
        position: 'absolute',
        top: '50%',
        left: '50%',
        width: size,
        height: size,
    } as const;

    return (
        <>
            <Icon
                style={{ ...box, translate: `-50% calc(-50% + ${offset}px)` }}
                color={lit}
                aria-hidden
            />
            <Icon
                style={{ ...box, translate: '-50% -50%' }}
                color={metal.ink}
                aria-hidden
            />
        </>
    );
}

export function Medal({
    rarity,
    icon,
    locked = false,
    size = 36,
    className,
}: {
    rarity: AchievementRarity;
    icon?: string | null;
    locked?: boolean;
    size?: number;
    className?: string;
}) {
    const id = useId();

    if (locked) {
        return (
            <svg
                viewBox="0 0 48 48"
                width={size}
                height={size}
                className={cn('shrink-0', className)}
                aria-hidden
            >
                <circle
                    cx="24"
                    cy="24"
                    r="20"
                    className="fill-muted stroke-muted-foreground"
                    strokeWidth="1.25"
                    strokeDasharray="3 3"
                />
            </svg>
        );
    }

    const Icon = (icon && ICONS[icon]) || TargetIcon;
    const metal = METALS[rarity];
    const { face, glyph, crown, ring } = GEOMETRY[rarity];
    // Obsidian's crown is gold; every other tier wears its own metal.
    const crownMetal = metal.bezel ?? metal;
    const crownId = metal.bezel ? `${id}-bezel-metal` : id;

    return (
        <span
            className={cn('relative inline-flex shrink-0', className)}
            style={{ width: size, height: size }}
        >
            <svg viewBox="0 0 48 48" width={size} height={size} aria-hidden>
                <defs>
                    <MetalDefs id={id} metal={metal} />
                    {metal.bezel && (
                        <MetalDefs id={crownId} metal={metal.bezel} />
                    )}
                    <clipPath id={`${id}-clip`}>
                        <circle cx="24" cy="24" r={face} />
                    </clipPath>
                </defs>

                {crown && (
                    <>
                        <polygon
                            points={CROWN}
                            fill={`url(#${crownId}-face)`}
                        />
                        <polygon
                            points={CROWN}
                            fill="none"
                            stroke={crownMetal.rim}
                            strokeOpacity="0.45"
                            strokeWidth="0.75"
                        />
                    </>
                )}

                {ring && (
                    <>
                        <circle
                            cx="24"
                            cy="24"
                            r="22.25"
                            fill="none"
                            stroke={`url(#${id}-face)`}
                            strokeWidth="1.5"
                        />
                        <circle
                            cx="24"
                            cy="24"
                            r="22.25"
                            fill="none"
                            stroke={metal.rim}
                            strokeOpacity="0.35"
                            strokeWidth="0.5"
                        />
                    </>
                )}

                <circle cx="24" cy="24" r={face} fill={`url(#${id}-face)`} />

                <g clipPath={`url(#${id}-clip)`}>
                    <ellipse
                        cx="18.5"
                        cy="16.5"
                        rx="13"
                        ry="8.5"
                        fill={`url(#${id}-sheen)`}
                        transform="rotate(-32 18.5 16.5)"
                    />
                </g>

                <circle
                    cx="24"
                    cy="24"
                    r={face - 0.75}
                    fill="none"
                    stroke={`url(#${id}-bevel)`}
                    strokeWidth="1.5"
                />
                <circle
                    cx="24"
                    cy="24"
                    r={face - 0.35}
                    fill="none"
                    stroke={metal.rim}
                    strokeOpacity="0.5"
                    strokeWidth="0.7"
                />

                {metal.bezel && (
                    <circle
                        cx="24"
                        cy="24"
                        r={face - 2.6}
                        fill="none"
                        stroke={metal.bezel.core}
                        strokeOpacity="0.8"
                        strokeWidth="0.9"
                    />
                )}
            </svg>

            <Engraving Icon={Icon} size={(glyph / 48) * size} metal={metal} />
        </span>
    );
}
