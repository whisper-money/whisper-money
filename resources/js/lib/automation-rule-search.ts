import { normalizeText } from '@/lib/full-import-profiles';
import type { AutomationRule } from '@/types/automation-rule';

/**
 * The rules whose title or category name contain the query, ignoring case,
 * accents and punctuation: "cafe" finds "Bares y cafeterías", "aa dia" finds
 * "AA · Dia". An empty query keeps every rule. The input order is kept.
 */
export function searchAutomationRules<
    T extends Pick<AutomationRule, 'title' | 'category'>,
>(rules: T[], query: string): T[] {
    const needle = normalizeText(query);
    if (!needle) {
        return rules;
    }

    return rules.filter((rule) =>
        [rule.title, rule.category?.name].some((text) =>
            normalizeText(text).includes(needle),
        ),
    );
}
