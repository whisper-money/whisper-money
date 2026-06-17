import { BankLogo } from '@/components/bank-logo';
import { IntegrationRequestsDrawer } from '@/components/integration-requests/integration-requests-drawer';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { getCsrfToken } from '@/lib/csrf';
import type {
    BankingConnection,
    EnableBankingInstitution,
} from '@/types/banking';
import { __ } from '@/utils/i18n';
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

const WISE_INSTITUTION: EnableBankingInstitution = {
    name: 'Wise',
    country: 'ALL',
    logo: '/images/banks/logos/wise.png',
    maximum_consent_validity: null,
};

interface ConnectAccountDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    connections?: BankingConnection[];
}

type Step = 'country' | 'bank' | 'confirm';

export function ConnectAccountDialog({
    open,
    onOpenChange,
    connections = [],
}: ConnectAccountDialogProps) {
    const [step, setStep] = useState<Step>('country');
    const [integrationDrawerOpen, setIntegrationDrawerOpen] = useState(false);
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
    const [wiseApiToken, setWiseApiToken] = useState('');

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

    const isWise = useMemo(() => selectedBank?.name === 'Wise', [selectedBank]);

    const resetState = useCallback(() => {
        setStep('country');
        setCountry('');
        setInstitutions([]);
        setFilteredInstitutions([]);
        setSearchQuery('');
        setSelectedBank(null);
        setIsLoading(false);
        setIsSubmitting(false);
        setError(null);
        setApiToken('');
        setApiKey('');
        setApiSecret('');
        setBitpandaApiKey('');
        setCoinbaseKeyName('');
        setCoinbasePrivateKey('');
        setWiseApiToken('');
    }, []);

    useEffect(() => {
        if (!open) {
            resetState();
        }
    }, [open, resetState]);

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

            const connectedEnableBankingNames = new Set(
                connections
                    .filter((c) => c.provider === 'enablebanking')
                    .map((c) => c.aspsp_name),
            );
            const hasProvider = (provider: string) =>
                connections.some((c) => c.provider === provider);

            const extraInstitutions = [
                BINANCE_INSTITUTION,
                BITPANDA_INSTITUTION,
                COINBASE_INSTITUTION,
                WISE_INSTITUTION,
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
                    return !connectedEnableBankingNames.has(institution.name);
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
        if (!selectedBank) return;

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
                      : isWise
                        ? '/open-banking/wise/connect'
                        : '/open-banking/authorize';

            const body = isBitpanda
                ? { api_key: bitpandaApiKey, country: country }
                : isBinance
                  ? { api_key: apiKey, api_secret: apiSecret, country: country }
                  : isIndexaCapital
                    ? { api_token: apiToken }
                    : isCoinbase
                      ? {
                            api_key_name: coinbaseKeyName,
                            private_key: coinbasePrivateKey,
                            country: country,
                        }
                      : isWise
                        ? { api_token: wiseApiToken }
                        : {
                              aspsp_name: selectedBank.name,
                              country: country,
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
        <>
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent className="sm:max-w-[500px]">
                    <DialogHeader>
                        <DialogTitle>{__('Connect Bank Account')}</DialogTitle>
                        <DialogDescription>
                            {step === 'country' &&
                                __(
                                    'Select the country where your bank is located.',
                                )}
                            {step === 'bank' && __('Select your bank.')}
                            {step === 'confirm' &&
                                isWise &&
                                __(
                                    'Enter your Wise Personal API token to connect your account.',
                                )}
                            {step === 'confirm' &&
                                !isIndexaCapital &&
                                !isBinance &&
                                !isBitpanda &&
                                !isCoinbase &&
                                !isWise &&
                                __(
                                    'You will be redirected to your bank to authorize access.',
                                )}
                            {step === 'confirm' &&
                                isIndexaCapital &&
                                __(
                                    'Enter your API token to connect your Indexa Capital account.',
                                )}
                            {step === 'confirm' &&
                                isBinance &&
                                __(
                                    'Enter your API Key and Secret to connect your Binance account.',
                                )}
                            {step === 'confirm' &&
                                isBitpanda &&
                                __(
                                    'Enter your API Key to connect your Bitpanda account.',
                                )}
                            {step === 'confirm' &&
                                isCoinbase &&
                                __(
                                    'Enter your CDP App Key ID and Secret to connect your Coinbase account.',
                                )}
                        </DialogDescription>
                    </DialogHeader>

                    {error && (
                        <p className="text-sm text-destructive">{error}</p>
                    )}

                    {step === 'country' && (
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <Label>{__('Country')}</Label>
                                <Select
                                    value={country}
                                    onValueChange={setCountry}
                                >
                                    <SelectTrigger>
                                        <SelectValue
                                            placeholder={__('Select country')}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {COUNTRIES.map((c) => (
                                            <SelectItem
                                                key={c.code}
                                                value={c.code}
                                            >
                                                {__(c.name)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {__('Cancel')}
                                </Button>
                                <Button
                                    disabled={!country || isLoading}
                                    onClick={() => fetchInstitutions(country)}
                                >
                                    {isLoading
                                        ? __('Loading...')
                                        : __('Continue')}
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
                            />

                            <div className="max-h-[300px] space-y-1 overflow-y-auto">
                                {filteredInstitutions.map((institution) => (
                                    <button
                                        key={institution.name}
                                        type="button"
                                        className={`flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm transition-colors hover:bg-accent ${
                                            selectedBank?.name ===
                                            institution.name
                                                ? 'bg-accent'
                                                : ''
                                        }`}
                                        onClick={() =>
                                            setSelectedBank(institution)
                                        }
                                    >
                                        <BankLogo
                                            src={institution.logo}
                                            className="h-6 w-6"
                                        />
                                        <span>{institution.name}</span>
                                    </button>
                                ))}
                                {filteredInstitutions.length === 0 && (
                                    <p className="py-4 text-center text-sm text-muted-foreground">
                                        {__('No banks found.')}
                                    </p>
                                )}
                            </div>

                            <button
                                type="button"
                                className="cursor-pointer border-b border-dotted text-sm text-primary hover:border-solid"
                                onClick={() => setIntegrationDrawerOpen(true)}
                            >
                                {__(
                                    "Can't find your bank? Request or vote for it",
                                )}
                            </button>

                            <div className="flex justify-end gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => setStep('country')}
                                >
                                    {__('Back')}
                                </Button>
                                <Button
                                    disabled={!selectedBank}
                                    onClick={() => setStep('confirm')}
                                >
                                    {__('Continue')}
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
                                        className="size-16 p-1"
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
                                                      : isWise
                                                        ? __(
                                                              'Connect your Wise account using a Personal API token.',
                                                          )
                                                        : __(
                                                              'You will be redirected to authorize access to your account data.',
                                                          )}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            {isIndexaCapital && (
                                <div className="space-y-2">
                                    <Label htmlFor="api-token">
                                        {__('API Token')}
                                    </Label>
                                    <Input
                                        id="api-token"
                                        type="password"
                                        value={apiToken}
                                        onChange={(e) =>
                                            setApiToken(e.target.value)
                                        }
                                        placeholder={__(
                                            'Paste your Indexa Capital API token',
                                        )}
                                        className="my-2"
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
                                        <Label htmlFor="api-key">
                                            {__('API Key')}
                                        </Label>
                                        <Input
                                            id="api-key"
                                            type="password"
                                            value={apiKey}
                                            onChange={(e) =>
                                                setApiKey(e.target.value)
                                            }
                                            className="mt-1"
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
                                            className="mt-1"
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
                                        className="mt-1"
                                        placeholder={__(
                                            'Paste your Bitpanda API Key',
                                        )}
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

                            {isWise && (
                                <div className="space-y-2">
                                    <Label htmlFor="wise-api-token">
                                        {__('Personal API Token')}
                                    </Label>
                                    <Input
                                        id="wise-api-token"
                                        type="password"
                                        value={wiseApiToken}
                                        onChange={(e) =>
                                            setWiseApiToken(e.target.value)
                                        }
                                        className="mt-1"
                                        placeholder={__(
                                            'Paste your Wise API token',
                                        )}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {__('Generate a token in Wise under')}{' '}
                                        <a
                                            href="https://wise.com/user/account#/developer"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="underline"
                                        >
                                            {__(
                                                'Settings → Developer Tools → API tokens',
                                            )}
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
                                                setCoinbaseKeyName(
                                                    e.target.value,
                                                )
                                            }
                                            className="mt-1 font-mono text-xs"
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
                                                setCoinbasePrivateKey(
                                                    e.target.value,
                                                )
                                            }
                                            rows={6}
                                            className="mt-1 font-mono text-xs"
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

                            <div className="flex justify-end gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => setStep('bank')}
                                    disabled={isSubmitting}
                                >
                                    {__('Back')}
                                </Button>
                                <Button
                                    onClick={handleAuthorize}
                                    disabled={
                                        isSubmitting ||
                                        (isIndexaCapital && !apiToken) ||
                                        (isBinance &&
                                            (!apiKey || !apiSecret)) ||
                                        (isBitpanda && !bitpandaApiKey) ||
                                        (isCoinbase &&
                                            (!coinbaseKeyName ||
                                                !coinbasePrivateKey)) ||
                                        (isWise && !wiseApiToken)
                                    }
                                >
                                    {isSubmitting
                                        ? __('Connecting...')
                                        : __('Connect')}
                                </Button>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            <IntegrationRequestsDrawer
                open={integrationDrawerOpen}
                onOpenChange={setIntegrationDrawerOpen}
            />
        </>
    );
}
