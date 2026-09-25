import { useLocale } from '@/hooks/use-locale';
import {
    alreadyConnectedBankNames,
    hasLiveConnectionForProvider,
} from '@/lib/banking-connections';
import {
    CONNECT_PROVIDERS,
    connectProviderForBank,
    isProviderComplete,
    postConnectRequest,
    providerConnectBody,
    providerInstitution,
    providersForCountry,
} from '@/lib/connect-providers';
import { getCsrfToken } from '@/lib/csrf';
import { leavePage } from '@/lib/leave-page';
import type {
    BankingConnection,
    EnableBankingInstitution,
} from '@/types/banking';
import { __ } from '@/utils/i18n';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

/** Countries we can connect banks in, most-used first. */
export const CONNECT_COUNTRY_CODES = [
    'ES',
    'DE',
    'FR',
    'IT',
    'NL',
    'PT',
    'BE',
    'AT',
    'FI',
    'IE',
    'LT',
    'LV',
    'EE',
    'SE',
    'NO',
    'DK',
    'PL',
    'GB',
] as const;

/**
 * The connectable countries named in the user's locale. Intl owns the country
 * names, so they never need a translation entry of their own.
 */
export function useConnectCountries(): { code: string; name: string }[] {
    const locale = useLocale();

    return useMemo(() => {
        const names = new Intl.DisplayNames([locale], { type: 'region' });

        return CONNECT_COUNTRY_CODES.map((code) => ({
            code,
            name: names.of(code) ?? code,
        }));
    }, [locale]);
}

export type ConnectStep = 'country' | 'bank' | 'confirm';

/** Where the last country the reader picked by hand is kept. */
const COUNTRY_STORAGE_KEY = 'connect:country';

/**
 * Remember a country the reader chose themselves, so the next visit to the
 * picker opens where the last one ended rather than back on a guess they have
 * already corrected once.
 */
export function rememberConnectCountry(code: string): void {
    try {
        localStorage.setItem(COUNTRY_STORAGE_KEY, code);
    } catch {
        // A browser with site data blocked still connects banks; it just asks
        // for the country every time.
    }
}

/** The remembered country, if it is still one we connect in. */
function rememberedCountry(): string | null {
    try {
        const code = localStorage.getItem(COUNTRY_STORAGE_KEY);

        return code &&
            (CONNECT_COUNTRY_CODES as readonly string[]).includes(code)
            ? code
            : null;
    } catch {
        return null;
    }
}

/**
 * The country the bank picker should open on, or null when nothing names one we
 * can connect in.
 *
 * The country is part of a bank's identity rather than a filter over one list:
 * `/aspsps` takes it as a required parameter and `startAuthorization()` is keyed
 * by the (name, country) pair, so Santander in Spain and Santander in Portugal
 * are two different connections. Guessing it from the region the user already
 * formats their money in is right often enough to make the full list a control
 * rather than a step.
 *
 * A country the reader picked themselves beats the guess: they have corrected
 * it once already, and a Spaniard reading the app in English was being sent
 * back to an empty United Kingdom list on every attempt.
 */
export function useGuessedCountry(): string | null {
    const locale = useLocale();

    return useMemo(() => {
        const remembered = rememberedCountry();

        if (remembered) {
            return remembered;
        }

        const region = new Intl.Locale(locale).region;

        return region &&
            (CONNECT_COUNTRY_CODES as readonly string[]).includes(region)
            ? region
            : null;
    }, [locale]);
}

interface ConnectFlowOptions {
    /**
     * Opens straight on the bank list for this country instead of asking for one
     * first. The onboarding flow guesses it and keeps the full list one tap away;
     * the settings dialog passes nothing and still asks.
     */
    initialCountry?: string | null;
    /**
     * Keeps the API-key providers out of the bank list. The onboarding flow
     * gives them a section of their own — a broker is not a bank, and its
     * confirm step promises different things — while the settings dialog still
     * shows them inline.
     */
    separateProviders?: boolean;
}

/**
 * Shared state and behavior for the bank-connect flow: country → bank list →
 * confirm/credentials → POST. Both the dialog and the inline flow consume this;
 * they only differ in chrome (layout, haptics, back navigation), which stays in
 * the components.
 */
export function useConnectFlow(
    connections: BankingConnection[],
    {
        initialCountry = null,
        separateProviders = false,
    }: ConnectFlowOptions = {},
) {
    const [step, setStep] = useState<ConnectStep>(
        initialCountry ? 'bank' : 'country',
    );
    const [country, setCountry] = useState(initialCountry ?? '');
    const [institutions, setInstitutions] = useState<
        EnableBankingInstitution[]
    >([]);
    const [filteredInstitutions, setFilteredInstitutions] = useState<
        EnableBankingInstitution[]
    >([]);
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedBank, setSelectedBank] =
        useState<EnableBankingInstitution | null>(null);
    const [isLoading, setIsLoading] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [credentials, setCredentials] = useState<Record<string, string>>({});
    const [acknowledgedReplace, setAcknowledgedReplace] = useState(false);

    const provider = useMemo(
        () => connectProviderForBank(selectedBank?.name),
        [selectedBank],
    );

    const connectedBankNames = useMemo(
        () => alreadyConnectedBankNames(connections),
        [connections],
    );

    const isAlreadyConnected = useMemo(
        () => !!selectedBank && connectedBankNames.has(selectedBank.name),
        [selectedBank, connectedBankNames],
    );

    const setCredential = useCallback((key: string, value: string) => {
        setCredentials((current) => ({ ...current, [key]: value }));
    }, []);

    useEffect(() => {
        setAcknowledgedReplace(false);
    }, [selectedBank]);

    useEffect(() => {
        setFilteredInstitutions(
            searchQuery
                ? institutions.filter((i) =>
                      i.name.toLowerCase().includes(searchQuery.toLowerCase()),
                  )
                : institutions,
        );
    }, [searchQuery, institutions]);

    const reset = useCallback(() => {
        setStep('country');
        setCountry('');
        setInstitutions([]);
        setFilteredInstitutions([]);
        setSearchQuery('');
        setSelectedBank(null);
        setIsLoading(false);
        setIsSubmitting(false);
        setError(null);
        setCredentials({});
        setAcknowledgedReplace(false);
    }, []);

    const clearBankSelection = useCallback(() => {
        setInstitutions([]);
        setFilteredInstitutions([]);
        setSearchQuery('');
        setSelectedBank(null);
    }, []);

    /**
     * Resolves with how many banks the country turned out to have, or null when
     * we could not ask — a country with none and a request that failed are two
     * different answers, and only one of them is about the country.
     */
    const fetchInstitutions = useCallback(
        async (countryCode: string): Promise<number | null> => {
            setIsLoading(true);
            setError(null);

            try {
                const response = await fetch(
                    `/open-banking/institutions?country=${countryCode}`,
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-XSRF-TOKEN': getCsrfToken(),
                        },
                    },
                );

                if (!response.ok) {
                    throw new Error('Failed to fetch banks');
                }

                const data = await response.json();

                const extraInstitutions = separateProviders
                    ? []
                    : providersForCountry(countryCode)
                          .filter(
                              (p) =>
                                  !hasLiveConnectionForProvider(
                                      connections,
                                      p.providerKey,
                                  ),
                          )
                          .map(providerInstitution);

                // A provider we integrate natively (e.g. Wise) must surface only
                // through its own entry, never the bank-aggregator's duplicate.
                const nativeNames = new Set(
                    CONNECT_PROVIDERS.map((p) => p.institution.name),
                );
                const fromProvider = (
                    data as EnableBankingInstitution[]
                ).filter((institution) => !nativeNames.has(institution.name));

                const allInstitutions = [
                    ...extraInstitutions,
                    ...fromProvider,
                ].sort((a, b) => a.name.localeCompare(b.name));

                setInstitutions(allInstitutions);
                setFilteredInstitutions(allInstitutions);
                setStep('bank');

                return allInstitutions.length;
            } catch {
                setError(__('Failed to load banks. Please try again.'));

                return null;
            } finally {
                setIsLoading(false);
            }
        },
        [connections, separateProviders],
    );

    // Only ever once: `fetchInstitutions` is rebuilt whenever `connections`
    // changes, and re-running this would throw away a country the user has since
    // picked by hand.
    const hasAutoFetched = useRef(false);

    useEffect(() => {
        if (initialCountry && !hasAutoFetched.current) {
            hasAutoFetched.current = true;

            // A guess that lands on a country with no banks behind it is a
            // guess that did not pay off, and "No banks found" is the first
            // thing somebody sees after paying to connect one. Ask instead —
            // but only for an empty country, never for a request that failed,
            // which has its own error to show where the reader already is.
            void fetchInstitutions(initialCountry).then((found) => {
                if (found === 0) {
                    setStep('country');
                }
            });
        }
    }, [initialCountry, fetchInstitutions]);

    const handleAuthorize = useCallback(async () => {
        if (!selectedBank) {
            return;
        }

        setIsSubmitting(true);
        setError(null);

        try {
            const redirectUrl = provider
                ? await postConnectRequest(
                      provider.endpoint,
                      providerConnectBody(provider, credentials, country),
                  )
                : await postConnectRequest('/open-banking/authorize', {
                      aspsp_name: selectedBank.name,
                      country,
                      logo: selectedBank.logo,
                  });

            leavePage(redirectUrl);
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : __('Failed to connect. Please try again.'),
            );
            setIsSubmitting(false);
        }
    }, [selectedBank, provider, credentials, country]);

    const canSubmit =
        !isSubmitting &&
        !(isAlreadyConnected && !acknowledgedReplace) &&
        (!provider || isProviderComplete(provider, credentials));

    return {
        step,
        setStep,
        country,
        setCountry,
        institutions,
        filteredInstitutions,
        searchQuery,
        setSearchQuery,
        selectedBank,
        setSelectedBank,
        isLoading,
        isSubmitting,
        error,
        credentials,
        setCredential,
        provider,
        connectedBankNames,
        isAlreadyConnected,
        acknowledgedReplace,
        setAcknowledgedReplace,
        canSubmit,
        fetchInstitutions,
        handleAuthorize,
        reset,
        clearBankSelection,
    };
}
