import { cn } from '@/lib/utils';
import { type AchievementRarity } from '@/types';
import { __ } from '@/utils/i18n';

/**
 * The tier a medal belongs to, and how many members hold it.
 *
 * The label is written in the metal of its own medal — copper, steel, gold,
 * obsidian's gold — so the card names the tier twice without repeating a word.
 * Weight still escalates underneath, which is what carries the order in
 * greyscale and for a reader who cannot separate the hues.
 *
 * A medal still to come stays grey: an empty slot is the road ahead, and
 * painting it in metal would promise a tier that has not been struck yet.
 *
 * The share is only ever present once enough members have been evaluated for a
 * percentage to mean something; below that the tier stands alone.
 */
const LABELS: Record<AchievementRarity, () => string> = {
    common: () => __('Common'),
    uncommon: () => __('Uncommon'),
    rare: () => __('Rare'),
    epic: () => __('Epic'),
};

const STYLES: Record<AchievementRarity, string> = {
    common: 'font-medium text-[var(--medal-copper)]',
    uncommon: 'font-semibold text-[var(--medal-steel)]',
    rare: 'font-semibold text-[var(--medal-gold)]',
    epic: 'font-bold text-[var(--medal-obsidian)]',
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
    return (
        <div
            title={title}
            className={cn(
                'text-[10px] leading-[14px] tracking-widest whitespace-nowrap uppercase',
                muted
                    ? cn(WEIGHTS[rarity], 'text-muted-foreground')
                    : STYLES[rarity],
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
