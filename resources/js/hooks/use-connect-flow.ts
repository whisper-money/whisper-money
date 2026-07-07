import {
    alreadyConnectedBankNames,
    hasLiveConnectionForProvider,
} from '@/lib/banking-connections';
import {
    CONNECT_PROVIDERS,
    connectProviderForBank,
    credentialPayload,
    isProviderComplete,
} from '@/lib/connect-providers';
import { getCsrfToken } from '@/lib/csrf';
import type {
    BankingConnection,
    EnableBankingInstitution,
} from '@/types/banking';
import { __ } from '@/utils/i18n';
import { useCallback, useEffect, useMemo, useState } from 'react';

export const CONNECT_COUNTRIES = [
    { code: 'ES', name: 'Spain' },
    { code: 'DE', name: 'Germany' },
    { code: 'FR', name: 'France' },
    { code: 'IT', name: 'Italy' },
    { code: 'NL', name: 'Netherlands' },
    { code: 'PT', name: 'Portugal' },
    { code: 'BE', name: 'Belgium' },
    { code: 'AT', name: 'Austria' },
    { code: 'FI', name: 'Finland' },
    { code: 'IE', name: 'Ireland' },
    { code: 'LT', name: 'Lithuania' },
    { code: 'LV', name: 'Latvia' },
    { code: 'EE', name: 'Estonia' },
    { code: 'SE', name: 'Sweden' },
    { code: 'NO', name: 'Norway' },
    { code: 'DK', name: 'Denmark' },
    { code: 'PL', name: 'Poland' },
    { code: 'GB', name: 'United Kingdom' },
] as const;

export type ConnectStep = 'country' | 'bank' | 'confirm';

/**
 * Shared state and behavior for the bank-connect flow: country → bank list →
 * confirm/credentials → POST. Both the dialog and the inline flow consume this;
 * they only differ in chrome (layout, haptics, back navigation), which stays in
 * the components.
 */
export function useConnectFlow(connections: BankingConnection[]) {
    const [step, setStep] = useState<ConnectStep>('country');
    const [country, setCountry] = useState('');
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

    const fetchInstitutions = useCallback(
        async (countryCode: string) => {
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

                const extraInstitutions = CONNECT_PROVIDERS.filter(
                    (p) =>
                        (!p.onlyCountry || p.onlyCountry === countryCode) &&
                        !hasLiveConnectionForProvider(
                            connections,
                            p.providerKey,
                        ),
                ).map((p) => p.institution);

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
            } catch {
                setError(__('Failed to load banks. Please try again.'));
            } finally {
                setIsLoading(false);
            }
        },
        [connections],
    );

    const postToBackend = useCallback(
        async (
            url: string,
            body: Record<string, unknown>,
        ): Promise<{ redirect_url: string } | null> => {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify(body),
            });

            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(
                    (data as { message?: string }).message ||
                        'Failed to start authorization',
                );
            }

            return response.json();
        },
        [],
    );

    const handlePlaidLink = useCallback(async () => {
        setIsSubmitting(true);
        setError(null);

        try {
            // 1. Get link token from backend
            const linkTokenResult = await postToBackend(
                '/open-banking/plaid/link-token',
                {},
            );

            if (!linkTokenResult) {
                throw new Error('Failed to create Plaid link token');
            }

            // linkTokenResult might just be { link_token, expiration } — not a redirect response
            // Re-fetch to get the link token directly
            const tokenResponse = await fetch(
                '/open-banking/plaid/link-token',
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': getCsrfToken(),
                    },
                    body: JSON.stringify({}),
                },
            );

            if (!tokenResponse.ok) {
                throw new Error('Failed to create Plaid link token');
            }

            const tokenData = await tokenResponse.json();
            const linkToken = tokenData.link_token;

            if (!linkToken) {
                throw new Error('No link token returned from Plaid');
            }

            // 2. Open Plaid Link
            const config: PlaidLinkConfig = {
                token: linkToken,
                onSuccess: async (publicToken: string) => {
                    try {
                        setIsSubmitting(true);
                        const result = await postToBackend(
                            '/open-banking/plaid/connect',
                            { public_token: publicToken },
                        );

                        if (result?.redirect_url) {
                            window.location.href = result.redirect_url;
                        }
                    } catch (e) {
                        setError(
                            e instanceof Error
                                ? e.message
                                : __('Failed to connect. Please try again.'),
                        );
                        setIsSubmitting(false);
                    }
                },
                onExit: () => {
                    setIsSubmitting(false);
                },
            };

            const plaid = (window as unknown as PlaidWindow).Plaid;
            if (plaid) {
                plaid.create(config).open();
            } else {
                // Load Plaid Link script dynamically
                const script = document.createElement('script');
                script.src =
                    'https://cdn.plaid.com/link/v2/stable/link-initialize.js';
                script.onload = () => {
                    const plaidLoaded = (window as unknown as PlaidWindow)
                        .Plaid;
                    if (plaidLoaded) {
                        plaidLoaded.create(config).open();
                    }
                };
                script.onerror = () => {
                    setError(
                        __('Failed to load Plaid. Please try again.'),
                    );
                    setIsSubmitting(false);
                };
                document.head.appendChild(script);
            }
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : __('Failed to connect. Please try again.'),
            );
            setIsSubmitting(false);
        }
    }, [postToBackend, __]);

    const handleAuthorize = useCallback(async () => {
        if (!selectedBank) {
            return;
        }

        // Plaid uses its own SDK flow
        if (provider?.usesSdk) {
            await handlePlaidLink();
            return;
        }

        setIsSubmitting(true);
        setError(null);

        try {
            const url = provider
                ? provider.endpoint
                : '/open-banking/authorize';

            const body = provider
                ? {
                      ...credentialPayload(provider, credentials),
                      ...(provider.sendsCountry ? { country } : {}),
                  }
                : {
                      aspsp_name: selectedBank.name,
                      country,
                      logo: selectedBank.logo,
                  };

            const result = await postToBackend(url, body);

            if (result?.redirect_url) {
                window.location.href = result.redirect_url;
            }
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : __('Failed to connect. Please try again.'),
            );
            setIsSubmitting(false);
        }
    }, [
        selectedBank,
        provider,
        credentials,
        country,
        postToBackend,
        handlePlaidLink,
        __,
    ]);

    const canSubmit =
        !isSubmitting &&
        !(isAlreadyConnected && !acknowledgedReplace) &&
        (!provider || isProviderComplete(provider, credentials));

    return {
        step,
        setStep,
        country,
        setCountry,
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

interface PlaidLinkConfig {
    token: string;
    onSuccess: (publicToken: string, metadata?: unknown) => void;
    onExit?: (error?: unknown, metadata?: unknown) => void;
    onLoad?: () => void;
    onEvent?: (eventName: string, metadata: unknown) => void;
}

interface PlaidStatic {
    create: (config: PlaidLinkConfig) => { open: () => void; destroy: () => void };
}

interface PlaidWindow {
    Plaid: PlaidStatic | undefined;
}
