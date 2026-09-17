import { type OnboardingSummary } from '@/hooks/use-onboarding-summary';

/**
 * The summary as it comes back from the server, wrapped the way axios hands it
 * over. Shared by the two steps written from it, which otherwise each carried
 * their own copy of the same twelve fields.
 *
 * The defaults are the user who did all of it: a year of a connected bank,
 * rules written and a €200 target. Every screen worth testing is that user with
 * something taken away, so each case says what it took rather than restating
 * the whole payload.
 */
export function onboardingSummaryResponse(
    overrides: Partial<OnboardingSummary> = {},
): { data: OnboardingSummary } {
    return {
        data: {
            currency_code: 'EUR',
            accounts: 4,
            connected_accounts: 4,
            transactions: 903,
            months: 12,
            rules: 28,
            monthly_spending: 184700,
            recurring_count: 9,
            recurring_amount: 21100,
            target: 20000,
            ...overrides,
        },
    };
}
