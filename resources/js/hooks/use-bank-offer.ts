import {
    isConnectCountry,
    rememberedConnectCountry,
} from '@/hooks/use-connect-flow';
import { type SharedData } from '@/types';
import { type SignupPlan } from '@/types/pricing';
import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';

/**
 * Whether the user sits in a country no bank of ours reaches, so the bank row
 * and the plan gate in front of it would sell them something they cannot use.
 * Most of the bank gate's views came from Colombia, the US, Mexico and
 * Argentina, where the connections were zero.
 *
 * Only a known country counts against them: with no timezone to go by, the
 * bank stays on offer. And a country they once picked by hand in the bank
 * picker beats where they are — a Spaniard abroad still banks in Spain.
 */
export function bankCountryOutOfReach(timezoneCountry: string | null): boolean {
    if (!timezoneCountry || rememberedConnectCountry()) {
        return false;
    }

    return !isConnectCountry(timezoneCountry);
}

export interface BankOffer {
    isFreePlan: boolean;
    /** Open banking is on, but no bank of ours reaches the user's country. */
    bankHiddenByCountry: boolean;
    /**
     * Whether onboarding offers a bank connection at all. There are three
     * reasons it may not: the free card buys none, a self-hosted install may
     * carry no open-banking credentials, and no bank of ours reaches the
     * user's country. Every screen that promises a bank asks this first, so
     * nobody reads about a bank login the accounts hub then never shows.
     */
    bankOffered: boolean;
}

/**
 * Whether the user will be offered a bank connection during onboarding. The
 * accounts hub decides which rows to show with it, and the screens before and
 * after it decide what to promise with the same answer.
 *
 * The country half looks at localStorage, so it is read once per `readKey`
 * rather than on every render. The hub reads it once per mount — it remounts
 * after every account, which is when a country picked by hand could change it
 * — and the onboarding page passes the current step, so a country picked in
 * the hub's bank picker reaches the screens after it.
 */
export function useBankOffer(
    signupPlan: SignupPlan | null,
    timezoneCountry: string | null,
    readKey?: unknown,
): BankOffer {
    const { openBankingEnabled } = usePage<SharedData>().props;
    const isFreePlan = signupPlan === 'free';
    const bankHiddenByCountry = useMemo(
        () =>
            openBankingEnabled &&
            !isFreePlan &&
            bankCountryOutOfReach(timezoneCountry),
        // `readKey` only forces a fresh read of localStorage.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [openBankingEnabled, isFreePlan, timezoneCountry, readKey],
    );

    return {
        isFreePlan,
        bankHiddenByCountry,
        bankOffered: !isFreePlan && openBankingEnabled && !bankHiddenByCountry,
    };
}
