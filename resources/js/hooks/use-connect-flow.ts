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
import type { SharedData } from '@/types';
import type {
    BankingConnection,
    EnableBankingInstitution,
} from '@/types/banking';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
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
    const { features } = usePage<SharedData>().props;
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
                        (!p.feature || features[p.feature]) &&
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
        [connections, features],
    );

    const handleAuthorize = useCallback(async () => {
        if (!selectedBank) {
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
                    data.message || 'Failed to start authorization',
                );
            }

            const data = await response.json();
            window.location.href = data.redirect_url;
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
