import { SankeyCategory } from '@/hooks/use-cashflow-data';

export interface GroupedCategory {
    categories: SankeyCategory[];
    total: number;
}

export interface GroupedCategoryResult {
    main: SankeyCategory[];
    other: GroupedCategory | null;
}

/**
 * Groups small categories (below threshold) into an "Other" category
 *
 * @param categories - Array of categories to potentially group
 * @param total - Total amount for calculating percentages
 * @param threshold - Percentage threshold (e.g., 0.05 for 5%)
 * @returns Object with main categories to display and grouped "other" categories
 */
export function groupSmallCategories(
    categories: SankeyCategory[],
    total: number,
    threshold: number = 0.03,
): GroupedCategoryResult {
    // If no total or empty categories, return as-is
    if (total === 0 || categories.length === 0) {
        return { main: categories, other: null };
    }

    // Sort by amount descending
    const sortedCategories = [...categories].sort(
        (a, b) => b.amount - a.amount,
    );

    // If we have 5 or fewer categories total, don't group
    if (sortedCategories.length <= 5) {
        return { main: sortedCategories, other: null };
    }

    const thresholdAmount = total * threshold;
    const mainCategories: SankeyCategory[] = [];
    const otherCategories: SankeyCategory[] = [];

    for (const category of sortedCategories) {
        // Keep categories that are:
        // 1. Above threshold amount, OR
        // 2. In the top 3 (ensure minimum visibility)
        if (category.amount >= thresholdAmount || mainCategories.length < 3) {
            mainCategories.push(category);
        } else {
            otherCategories.push(category);
        }
    }

    // Only create "Other" group if:
    // 1. We have at least 2 categories to group
    // 2. We're showing at least 3 main categories
    if (otherCategories.length >= 2 && mainCategories.length >= 3) {
        const otherTotal = otherCategories.reduce(
            (sum, cat) => sum + cat.amount,
            0,
        );

        return {
            main: mainCategories,
            other: {
                categories: otherCategories,
                total: otherTotal,
            },
        };
    }

    // Don't group - return all categories as main
    return { main: sortedCategories, other: null };
}

/**
 * Formats an amount as its whole-number share of a total, e.g. `40%`.
 *
 * Shares are rounded independently, so a set can land on 99% or 101% — that
 * reads better than redistributing remainders onto an arbitrary slice. Returns
 * null when there is no usable denominator, so the caller shows the amount
 * alone instead of `0%` or `NaN%`.
 *
 * The amount may be negative (the hub's net in a month that overspent), in
 * which case so is the share.
 */
export function formatShare(amount: number, total: number): string | null {
    if (!total || total < 0) {
        return null;
    }

    const percent = (amount / total) * 100;

    // A share too small to round to a whole percent still exists; `0%` would
    // deny it, and on the hub it would read a deficit as having broken even.
    if (percent !== 0 && Math.abs(percent) < 0.5) {
        return percent > 0 ? '<1%' : '>-1%';
    }

    return `${Math.round(percent)}%`;
}
