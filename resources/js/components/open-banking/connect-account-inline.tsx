import { BankLogo } from '@/components/bank-logo';
import { ReplaceConnectionWarning } from '@/components/open-banking/replace-connection-warning';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useWebHaptics } from '@/hooks/use-web-haptics';
import {
    alreadyConnectedBankNames,
    hasLiveConnectionForProvider,
} from '@/lib/banking-connections';
import {
    CONNECT_PROVIDERS,
    connectProviderForBank,
    credentialPayload,
    isProviderComplete,
    ProviderCredentialFields,
} from '@/lib/connect-providers';
import { getCsrfToken } from '@/lib/csrf';
import type { SharedData } from '@/types';
import type {
    BankingConnection,
    EnableBankingInstitution,
} from '@/types/banking';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

const COUNTRIES = [
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

type Step = 'country' | 'bank' | 'confirm';

interface ConnectAccountInlineProps {
    onBack: () => void;
    connections?: BankingConnection[];
}

export function ConnectAccountInline({
    onBack,
    connections = [],
}: ConnectAccountInlineProps) {
    const { features } = usePage<SharedData>().props;
    const [step, setStep] = useState<Step>('country');
    const { trigger } = useWebHaptics();
    const [country, setCountry] = useState<string>('');
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
        if (searchQuery) {
            setFilteredInstitutions(
                institutions.filter((i) =>
                    i.name.toLowerCase().includes(searchQuery.toLowerCase()),
                ),
            );
        } else {
            setFilteredInstitutions(institutions);
        }
    }, [searchQuery, institutions]);

    const handleBack = useCallback(() => {
        if (step === 'country') {
            onBack();
        } else if (step === 'bank') {
            setStep('country');
            setInstitutions([]);
            setFilteredInstitutions([]);
            setSearchQuery('');
            setSelectedBank(null);
        } else if (step === 'confirm') {
            setStep('bank');
        }
    }, [step, onBack]);

    async function fetchInstitutions(countryCode: string) {
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
                    !hasLiveConnectionForProvider(connections, p.providerKey),
            ).map((p) => p.institution);

            const allInstitutions = [...extraInstitutions, ...data].sort(
                (a, b) => a.name.localeCompare(b.name),
            );

            setInstitutions(allInstitutions);
            setFilteredInstitutions(allInstitutions);
            setStep('bank');
        } catch {
            setError(__('Failed to load banks. Please try again.'));
        } finally {
            setIsLoading(false);
        }
    }

    async function handleAuthorize() {
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
    }

    const canSubmit =
        !isSubmitting &&
        !(isAlreadyConnected && !acknowledgedReplace) &&
        (!provider || isProviderComplete(provider, credentials));

    return (
        <div className="w-full max-w-md space-y-4">
            {error && (
                <p className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">
                    {error}
                </p>
            )}

            {step === 'country' && (
                <div className="space-y-4">
                    <div className="space-y-2">
                        <Label>{__('Country')}</Label>
                        <Select value={country} onValueChange={setCountry}>
                            <SelectTrigger>
                                <SelectValue
                                    placeholder={__('Select country')}
                                />
                            </SelectTrigger>
                            <SelectContent>
                                {COUNTRIES.map((c) => (
                                    <SelectItem key={c.code} value={c.code}>
                                        {__(c.name)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-2">
                        <Button
                            className="w-full"
                            size="lg"
                            disabled={!country || isLoading}
                            onClick={() => fetchInstitutions(country)}
                        >
                            {isLoading ? __('Loading...') : __('Continue')}
                        </Button>

                        <Button
                            variant={'ghost'}
                            type="button"
                            onClick={() => {
                                trigger('light');
                                handleBack();
                            }}
                            className="w-full"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            {__('Back')}
                        </Button>
                    </div>
                </div>
            )}

            {step === 'bank' && (
                <div className="space-y-4">
                    <Input
                        placeholder={__('Search banks...')}
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        autoFocus
                    />

                    <div className="max-h-[300px] space-y-1 overflow-y-auto rounded-lg border p-1">
                        {filteredInstitutions.map((institution) => (
                            <button
                                key={institution.name}
                                type="button"
                                className={`flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm transition-colors hover:bg-accent ${
                                    selectedBank?.name === institution.name
                                        ? 'bg-accent'
                                        : ''
                                }`}
                                onClick={() => setSelectedBank(institution)}
                            >
                                <BankLogo
                                    src={institution.logo}
                                    className="h-6 w-6"
                                />
                                <span>{institution.name}</span>
                                {connectedBankNames.has(institution.name) && (
                                    <Badge
                                        variant="secondary"
                                        className="ml-auto"
                                    >
                                        {__('Already connected')}
                                    </Badge>
                                )}
                            </button>
                        ))}
                        {filteredInstitutions.length === 0 && (
                            <p className="py-4 text-center text-sm text-muted-foreground">
                                {__('No banks found.')}
                            </p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Button
                            className="w-full"
                            size="lg"
                            disabled={!selectedBank}
                            onClick={() => setStep('confirm')}
                        >
                            {__('Continue')}
                        </Button>
                        <Button
                            variant={'ghost'}
                            type="button"
                            onClick={() => {
                                trigger('light');
                                handleBack();
                            }}
                            className="w-full"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            {__('Back')}
                        </Button>
                    </div>
                </div>
            )}

            {step === 'confirm' && selectedBank && (
                <div className="space-y-4">
                    <div className="rounded-lg border p-4">
                        <div className="flex items-center gap-3">
                            <BankLogo
                                src={selectedBank.logo}
                                className="size-12 p-1"
                            />
                            <div>
                                <p className="font-medium">
                                    {selectedBank.name}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {provider
                                        ? __(provider.cardDescription)
                                        : __(
                                              'You will be redirected to authorize access to your account data.',
                                          )}
                                </p>
                            </div>
                        </div>
                    </div>

                    {isAlreadyConnected && (
                        <ReplaceConnectionWarning
                            acknowledged={acknowledgedReplace}
                            onAcknowledgedChange={setAcknowledgedReplace}
                        />
                    )}

                    {provider && (
                        <ProviderCredentialFields
                            provider={provider}
                            values={credentials}
                            onChange={setCredential}
                            idPrefix="inline"
                        />
                    )}

                    <Button
                        className="w-full"
                        size="lg"
                        onClick={handleAuthorize}
                        disabled={!canSubmit}
                    >
                        {isSubmitting ? __('Connecting...') : __('Connect')}
                    </Button>
                </div>
            )}
        </div>
    );
}
