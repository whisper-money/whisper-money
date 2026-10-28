import type { AutomationRule } from '@/types/automation-rule';

/**
 * Lower case, no accents, every run of anything that is not a letter or a
 * digit (in any script) as one space.
 */
function foldForSearch(value: string | undefined): string {
    return (value ?? '')
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .toLowerCase()
        .replace(/[^\p{L}\p{N}]+/gu, ' ')
        .trim();
}

/**
 * The rules whose title or category name contain the query, ignoring case,
 * accents and punctuation: "cafe" finds "Bares y cafeterías", "aa dia" finds
 * "AA · Dia". An empty query keeps every rule. The input order is kept.
 */
export function searchAutomationRules<
    T extends Pick<AutomationRule, 'title' | 'category'>,
>(rules: T[], query: string): T[] {
    const needle = foldForSearch(query);
    if (!needle) {
        return rules;
    }

    return rules.filter((rule) =>
        [rule.title, rule.category?.name].some((text) =>
            foldForSearch(text).includes(needle),
        ),
    );
}
