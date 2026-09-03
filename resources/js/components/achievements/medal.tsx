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

/**
 * The medal.
 *
 * The palette is monochrome by design, so rarity cannot be a colour: it is the
 * shape of the rim instead. A plain disc is common, a disc inside a ring is
 * uncommon, a serrated rosette is rare, and a rosette with an inner ring is
 * epic. The same order reads in grayscale, in dark mode and on a printed card.
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

/** A star polygon with `points` teeth, used for the two top tiers. */
function rosette(points: number, outer: number, inner: number): string {
    return Array.from({ length: points * 2 }, (_, index) => {
        const angle = (Math.PI * index) / points - Math.PI / 2;
        const radius = index % 2 === 0 ? outer : inner;

        return `${(24 + radius * Math.cos(angle)).toFixed(2)},${(24 + radius * Math.sin(angle)).toFixed(2)}`;
    }).join(' ');
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
    const serrated = rarity === 'rare' || rarity === 'epic';
    const glyphSize = rarity === 'epic' ? 18 : rarity === 'uncommon' ? 20 : 22;

    return (
        <span
            className={cn('relative inline-flex shrink-0', className)}
            style={{ width: size, height: size }}
        >
            <svg viewBox="0 0 48 48" width={size} height={size} aria-hidden>
                {serrated ? (
                    <polygon
                        points={rosette(18, 23.5, 20.5)}
                        className="fill-foreground"
                    />
                ) : (
                    <>
                        {rarity === 'uncommon' && (
                            <circle
                                cx="24"
                                cy="24"
                                r="23"
                                fill="none"
                                className="stroke-foreground"
                                strokeWidth="1.5"
                            />
                        )}
                        <circle
                            cx="24"
                            cy="24"
                            r={rarity === 'uncommon' ? 18.5 : 20}
                            className="fill-foreground"
                        />
                    </>
                )}
                {rarity === 'epic' && (
                    <circle
                        cx="24"
                        cy="24"
                        r="16.75"
                        fill="none"
                        className="stroke-background"
                        strokeWidth="1.25"
                    />
                )}
            </svg>
            <Icon
                className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 text-background"
                style={{
                    width: (glyphSize / 48) * size,
                    height: (glyphSize / 48) * size,
                }}
            />
        </span>
    );
}
