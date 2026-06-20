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
import { Textarea } from '@/components/ui/textarea';
import { useWebHaptics } from '@/hooks/use-web-haptics';
import {
    alreadyConnectedBankNames,
    hasLiveConnectionForProvider,
} from '@/lib/banking-connections';
import { getCsrfToken } from '@/lib/csrf';
import type {
    BankingConnection,
    EnableBankingInstitution,
} from '@/types/banking';
import { __ } from '@/utils/i18n';
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

const INDEXA_CAPITAL_INSTITUTION: EnableBankingInstitution = {
    name: 'Indexa Capital',
    country: 'ES',
    logo: '/images/banks/logos/indexa-capital.jpg',
    maximum_consent_validity: null,
};

const BINANCE_INSTITUTION: EnableBankingInstitution = {
    name: 'Binance',
    country: 'ALL',
    logo: 'https://whisper.money/storage/banks/logos/t1h5rqi19dJTPl6ZadziPjNwm0lrcdTFBRzB3iCy.png',
    maximum_consent_validity: null,
};

const BITPANDA_INSTITUTION: EnableBankingInstitution = {
    name: 'Bitpanda',
    country: 'ALL',
    logo: 'https://whisper.money/storage/banks/logos/7Y6gl0gaFH1mStJMcUQ9VpgzX1kduyumm0dDhGlf.png',
    maximum_consent_validity: null,
};

const COINBASE_INSTITUTION: EnableBankingInstitution = {
    name: 'Coinbase',
    country: 'ALL',
    logo: 'https://whisper.money/storage/banks/logos/coinbase.png',
    maximum_consent_validity: null,
};

type Step = 'country' | 'bank' | 'confirm';

interface ConnectAccountInlineProps {
    onBack: () => void;
    connections?: BankingConnection[];
}

export function ConnectAccountInline({
    onBack,
    connections = [],
}: ConnectAccountInlineProps) {
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
    const [apiToken, setApiToken] = useState('');
    const [apiKey, setApiKey] = useState('');
    const [apiSecret, setApiSecret] = useState('');
    const [bitpandaApiKey, setBitpandaApiKey] = useState('');
    const [coinbaseKeyName, setCoinbaseKeyName] = useState('');
    const [coinbasePrivateKey, setCoinbasePrivateKey] = useState('');
    const [acknowledgedReplace, setAcknowledgedReplace] = useState(false);

    const isIndexaCapital = useMemo(
        () => selectedBank?.name === 'Indexa Capital',
        [selectedBank],
    );
    const isBinance = useMemo(
        () => selectedBank?.name === 'Binance',
        [selectedBank],
    );
    const isBitpanda = useMemo(
        () => selectedBank?.name === 'Bitpanda',
        [selectedBank],
    );
    const isCoinbase = useMemo(
        () => selectedBank?.name === 'Coinbase',
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

            const hasProvider = (provider: string) =>
                hasLiveConnectionForProvider(connections, provider);

            const extraInstitutions = [
                BINANCE_INSTITUTION,
                BITPANDA_INSTITUTION,
                COINBASE_INSTITUTION,
            ];
            if (countryCode === 'ES') {
                extraInstitutions.push(INDEXA_CAPITAL_INSTITUTION);
            }

            const allInstitutions = [...extraInstitutions, ...data]
                .filter((institution) => {
                    if (institution.name === 'Binance') {
                        return !hasProvider('binance');
                    }
                    if (institution.name === 'Bitpanda') {
                        return !hasProvider('bitpanda');
                    }
                    if (institution.name === 'Coinbase') {
                        return !hasProvider('coinbase');
                    }
                    if (institution.name === 'Indexa Capital') {
                        return !hasProvider('indexacapital');
                    }
                    return true;
                })
                .sort((a, b) => a.name.localeCompare(b.name));

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
            const url = isBitpanda
                ? '/open-banking/bitpanda/connect'
                : isBinance
                  ? '/open-banking/binance/connect'
                  : isIndexaCapital
                    ? '/open-banking/indexa-capital/connect'
                    : isCoinbase
                      ? '/open-banking/coinbase/connect'
                      : '/open-banking/authorize';

            const body = isBitpanda
                ? { api_key: bitpandaApiKey, country }
                : isBinance
                  ? { api_key: apiKey, api_secret: apiSecret, country }
                  : isIndexaCapital
                    ? { api_token: apiToken }
                    : isCoinbase
                      ? {
                            api_key_name: coinbaseKeyName,
                            private_key: coinbasePrivateKey,
                            country,
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
                                    {isBitpanda
                                        ? __(
                                              'Connect your Bitpanda account using your API Key.',
                                          )
                                        : isBinance
                                          ? __(
                                                'Connect your Binance account using your API Key and Secret.',
                                            )
                                          : isIndexaCapital
                                            ? __(
                                                  'Connect your Indexa Capital account using your API token.',
                                              )
                                            : isCoinbase
                                              ? __(
                                                    'Connect your Coinbase account using a CDP API key.',
                                                )
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

                    {isIndexaCapital && (
                        <div className="space-y-2">
                            <Label htmlFor="api-token">{__('API Token')}</Label>
                            <Input
                                id="api-token"
                                type="password"
                                value={apiToken}
                                onChange={(e) => setApiToken(e.target.value)}
                                placeholder={__(
                                    'Paste your Indexa Capital API token',
                                )}
                            />
                            <p className="text-xs text-muted-foreground">
                                {__(
                                    'You can generate your API token from your Indexa Capital dashboard under',
                                )}{' '}
                                <a
                                    href="https://indexacapital.com/es/u/user#settings-apps"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="underline"
                                >
                                    {__('Settings > Applications')}
                                </a>
                                .
                            </p>
                        </div>
                    )}

                    {isBinance && (
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="api-key">{__('API Key')}</Label>
                                <Input
                                    id="api-key"
                                    type="password"
                                    value={apiKey}
                                    onChange={(e) => setApiKey(e.target.value)}
                                    placeholder={__(
                                        'Paste your Binance API Key',
                                    )}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="api-secret">
                                    {__('API Secret')}
                                </Label>
                                <Input
                                    id="api-secret"
                                    type="password"
                                    value={apiSecret}
                                    onChange={(e) =>
                                        setApiSecret(e.target.value)
                                    }
                                    placeholder={__(
                                        'Paste your Binance API Secret',
                                    )}
                                />
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {__(
                                    'You can create API keys from your Binance account under',
                                )}{' '}
                                <a
                                    href="https://www.binance.com/es/my/settings/api-management"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="underline"
                                >
                                    {__('API Management')}
                                </a>
                                .
                            </p>
                        </div>
                    )}

                    {isBitpanda && (
                        <div className="space-y-2">
                            <Label htmlFor="bitpanda-api-key">
                                {__('API Key')}
                            </Label>
                            <Input
                                id="bitpanda-api-key"
                                type="password"
                                value={bitpandaApiKey}
                                onChange={(e) =>
                                    setBitpandaApiKey(e.target.value)
                                }
                                placeholder={__('Paste your Bitpanda API Key')}
                            />
                            <p className="text-xs text-muted-foreground">
                                {__(
                                    'You can create API keys from your Bitpanda account under',
                                )}{' '}
                                <a
                                    href="https://web.bitpanda.com/apikey"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="underline"
                                >
                                    {__('API Key Management')}
                                </a>
                                .
                            </p>
                        </div>
                    )}

                    {isCoinbase && (
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="coinbase-key-name">
                                    {__('App Key ID')}
                                </Label>
                                <Input
                                    id="coinbase-key-name"
                                    type="text"
                                    value={coinbaseKeyName}
                                    onChange={(e) =>
                                        setCoinbaseKeyName(e.target.value)
                                    }
                                    className="font-mono text-xs"
                                    placeholder="00000000-0000-0000-0000-000000000000"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="coinbase-private-key">
                                    {__('Secret')}
                                </Label>
                                <Textarea
                                    id="coinbase-private-key"
                                    value={coinbasePrivateKey}
                                    onChange={(e) =>
                                        setCoinbasePrivateKey(e.target.value)
                                    }
                                    rows={6}
                                    className="font-mono text-xs"
                                    placeholder={
                                        'Paste your CDP API key secret'
                                    }
                                />
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {__(
                                    'Create a CDP API key (Ed25519 recommended) in the Coinbase Developer Platform under',
                                )}{' '}
                                <a
                                    href="https://portal.cdp.coinbase.com/access/api"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="underline"
                                >
                                    {__('API Keys')}
                                </a>
                                . {__('Use a view-only key.')}
                            </p>
                        </div>
                    )}

                    <Button
                        className="w-full"
                        size="lg"
                        onClick={handleAuthorize}
                        disabled={
                            isSubmitting ||
                            (isAlreadyConnected && !acknowledgedReplace) ||
                            (isIndexaCapital && !apiToken) ||
                            (isBinance && (!apiKey || !apiSecret)) ||
                            (isBitpanda && !bitpandaApiKey) ||
                            (isCoinbase &&
                                (!coinbaseKeyName || !coinbasePrivateKey))
                        }
                    >
                        {isSubmitting ? __('Connecting...') : __('Connect')}
                    </Button>
                </div>
            )}
        </div>
    );
}
