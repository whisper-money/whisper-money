import { cn } from '@/lib/utils';
import { type AchievementRarity } from '@/types';
import { __ } from '@/utils/i18n';

/**
 * The tier a medal belongs to, and how many members hold it.
 *
 * No colour: the palette is monochrome, so the tier escalates in weight and in
 * ink instead — a common medal is a light grey label, an epic one is bold and
 * full-strength. The share is only ever present once enough members have been
 * evaluated for a percentage to mean something; below that the tier stands
 * alone.
 */
const LABELS: Record<AchievementRarity, () => string> = {
    common: () => __('Common'),
    uncommon: () => __('Uncommon'),
    rare: () => __('Rare'),
    epic: () => __('Epic'),
};

const WEIGHTS: Record<AchievementRarity, string> = {
    common: 'font-medium',
    uncommon: 'font-semibold',
    rare: 'font-semibold',
    epic: 'font-bold',
};

export function RarityTag({
    rarity,
    share = null,
    muted = false,
    title,
    className,
}: {
    rarity: AchievementRarity;
    share?: number | null;
    muted?: boolean;
    title?: string;
    className?: string;
}) {
    const strong = rarity === 'rare' || rarity === 'epic';

    return (
        <div
            title={title}
            className={cn(
                'text-[10px] leading-[14px] tracking-widest whitespace-nowrap uppercase',
                WEIGHTS[rarity],
                muted || !strong ? 'text-muted-foreground' : 'text-foreground',
                className,
            )}
        >
            {LABELS[rarity]()}
            {share !== null && (
                <span className="font-normal text-muted-foreground">
                    {' · '}
                    {share}%
                </span>
            )}
        </div>
    );
}
