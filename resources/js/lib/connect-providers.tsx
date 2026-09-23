import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { getCsrfToken } from '@/lib/csrf';
import { cn } from '@/lib/utils';
import type { EnableBankingInstitution } from '@/types/banking';
import { __ } from '@/utils/i18n';

/**
 * Single source of truth for the API-key banking providers.
 *
 * Every provider used to be branched on in ~9 places across the connect
 * dialog, the inline connect flow and the update-credentials dialog. They are
 * now described once here: the registry drives the institution list, the
 * request endpoint/body, the on-screen copy and the credential form. Adding a
 * provider is a single entry; consent-based EnableBanking has no entry and is
 * handled as the default redirect flow by the consumers.
 */

export type CredentialField = {
    /** Doubles as the POST body key and the form-state key. */
    key: string;
    /** i18n key for the field label. */
    label: string;
    type: 'password' | 'text' | 'textarea';
    /** i18n key for a translated placeholder. */
    placeholder?: string;
    /** Literal, non-translatable placeholder (e.g. an example value). */
    placeholderExample?: string;
    mono?: boolean;
    small?: boolean;
};

export type ConnectProvider = {
    /** `banking_connections.provider` value. */
    providerKey: string;
    institution: EnableBankingInstitution;
    /** Connect endpoint (the update flow always PATCHes the connection). */
    endpoint: string;
    /** Whether the connect request also sends the selected `country`. */
    sendsCountry?: boolean;
    /**
     * Whether the provider hands over movements as well as balances. False for
     * every broker and exchange here — they report what you hold, not what you
     * bought — and true only for Wise, which is a current account wearing an
     * API key. It decides what the connect screen promises, so a user is never
     * left waiting for transactions that are not coming.
     */
    sendsTransactions?: boolean;
    /** Only offered when connecting from this country (e.g. Indexa: ES). */
    onlyCountry?: string;
    /**
     * ISO date (YYYY-MM-DD) until which our own integration is flagged as beta
     * in the picker and on the confirm step. A test fails once it has passed,
     * so the flag gets removed instead of lingering.
     */
    betaUntil?: string;
    /** Confirm-step header copy (i18n key). */
    headerDescription: string;
    /** Confirm-card copy (i18n key). */
    cardDescription: string;
    fields: CredentialField[];
    help: { before: string; href: string; link: string; after?: string };
};

export const CONNECT_PROVIDERS: ConnectProvider[] = [
    {
        providerKey: 'indexacapital',
        institution: {
            name: 'Indexa Capital',
            country: 'ES',
            logo: '/images/banks/logos/indexa-capital.jpg',
            maximum_consent_validity: null,
        },
        endpoint: '/open-banking/indexa-capital/connect',
        onlyCountry: 'ES',
        headerDescription:
            'Enter your API token to connect your Indexa Capital account.',
        cardDescription:
            'Connect your Indexa Capital account using your API token.',
        fields: [
            {
                key: 'api_token',
                label: 'API Token',
                type: 'password',
                placeholder: 'Paste your Indexa Capital API token',
            },
        ],
        help: {
            before: 'You can generate your API token from your Indexa Capital dashboard under',
            href: 'https://indexacapital.com/es/u/user#settings-apps',
            link: 'Settings > Applications',
        },
    },
    {
        providerKey: 'coinbase',
        institution: {
            name: 'Coinbase',
            country: 'ALL',
            logo: 'https://whisper.money/storage/banks/logos/coinbase.png',
            maximum_consent_validity: null,
        },
        endpoint: '/open-banking/coinbase/connect',
        sendsCountry: true,
        headerDescription:
            'Enter your CDP App Key ID and Secret to connect your Coinbase account.',
        cardDescription: 'Connect your Coinbase account using a CDP API key.',
        fields: [
            {
                key: 'api_key_name',
                label: 'App Key ID',
                type: 'text',
                placeholderExample: '00000000-0000-0000-0000-000000000000',
                mono: true,
                small: true,
            },
            {
                key: 'private_key',
                label: 'Secret',
                type: 'textarea',
                placeholderExample: 'Paste your CDP API key secret',
                mono: true,
                small: true,
            },
        ],
        help: {
            before: 'Create a CDP API key (Ed25519 recommended) in the Coinbase Developer Platform under',
            href: 'https://portal.cdp.coinbase.com/access/api',
            link: 'API Keys',
            after: 'Use a view-only key, and tick "Opt-out of IP allowlisting" so the key works from our servers.',
        },
    },
    {
        providerKey: 'binance',
        institution: {
            name: 'Binance',
            country: 'ALL',
            logo: 'https://whisper.money/storage/banks/logos/t1h5rqi19dJTPl6ZadziPjNwm0lrcdTFBRzB3iCy.png',
            maximum_consent_validity: null,
        },
        endpoint: '/open-banking/binance/connect',
        sendsCountry: true,
        headerDescription:
            'Enter your API Key and Secret to connect your Binance account.',
        cardDescription:
            'Connect your Binance account using your API Key and Secret.',
        fields: [
            {
                key: 'api_key',
                label: 'API Key',
                type: 'password',
                placeholder: 'Paste your Binance API Key',
            },
            {
                key: 'api_secret',
                label: 'API Secret',
                type: 'password',
                placeholder: 'Paste your Binance API Secret',
            },
        ],
        help: {
            before: 'You can create API keys from your Binance account under',
            href: 'https://www.binance.com/es/my/settings/api-management',
            link: 'API Management',
        },
    },
    {
        providerKey: 'bitpanda',
        institution: {
            name: 'Bitpanda',
            country: 'ALL',
            logo: 'https://whisper.money/storage/banks/logos/7Y6gl0gaFH1mStJMcUQ9VpgzX1kduyumm0dDhGlf.png',
            maximum_consent_validity: null,
        },
        endpoint: '/open-banking/bitpanda/connect',
        sendsCountry: true,
        headerDescription:
            'Enter your API Key to connect your Bitpanda account.',
        cardDescription: 'Connect your Bitpanda account using your API Key.',
        fields: [
            {
                key: 'api_key',
                label: 'API Key',
                type: 'password',
                placeholder: 'Paste your Bitpanda API Key',
            },
        ],
        help: {
            before: 'You can create API keys from your Bitpanda account under',
            href: 'https://web.bitpanda.com/apikey',
            link: 'API Key Management',
        },
    },
    {
        providerKey: 'kraken',
        institution: {
            name: 'Kraken',
            country: 'ALL',
            logo: '/images/banks/logos/kraken.png',
            maximum_consent_validity: null,
        },
        endpoint: '/open-banking/kraken/connect',
        sendsCountry: true,
        betaUntil: '2026-11-23',
        headerDescription:
            'Enter your API Key and Private Key to connect your Kraken account.',
        cardDescription:
            'Connect your Kraken account using your API Key and Private Key.',
        fields: [
            {
                key: 'api_key',
                label: 'API Key',
                type: 'password',
                placeholder: 'Paste your Kraken API Key',
            },
            {
                key: 'api_secret',
                label: 'Private Key',
                type: 'password',
                placeholder: 'Paste your Kraken Private Key',
            },
        ],
        help: {
            before: 'Create an API key in Kraken under',
            href: 'https://pro.kraken.com/app/settings/api',
            link: 'Settings → API',
            after: 'Enable only the "Query Funds" and "Query Ledger Entries" permissions: both are required, and nothing else is needed.',
        },
    },
    {
        providerKey: 'wise',
        institution: {
            name: 'Wise',
            country: 'ALL',
            logo: '/images/banks/logos/wise.png',
            maximum_consent_validity: null,
        },
        endpoint: '/open-banking/wise/connect',
        sendsTransactions: true,
        headerDescription:
            'Enter your Wise Personal API token to connect your account.',
        cardDescription:
            'Connect your Wise account using a Personal API token.',
        fields: [
            {
                key: 'api_token',
                label: 'Personal API Token',
                type: 'password',
                placeholder: 'Paste your Wise API token',
            },
        ],
        help: {
            before: 'Generate a token in Wise under',
            href: 'https://wise.com/user/account#/developer',
            link: 'Settings → Developer Tools → API tokens',
        },
    },
    {
        providerKey: 'interactivebrokers',
        institution: {
            name: 'Interactive Brokers',
            country: 'ALL',
            logo: '/images/banks/logos/interactive-brokers.png',
            maximum_consent_validity: null,
        },
        endpoint: '/open-banking/interactive-brokers/connect',
        headerDescription:
            'Enter your Flex Web Service token and Query ID to connect your Interactive Brokers account.',
        cardDescription:
            'Connect your Interactive Brokers account using a Flex Web Service token and Query ID.',
        fields: [
            {
                key: 'token',
                label: 'Flex Web Service Token',
                type: 'password',
                placeholder: 'Paste your Flex Web Service token',
            },
            {
                key: 'query_id',
                label: 'Flex Query ID',
                type: 'text',
                placeholderExample: '123456',
                mono: true,
            },
        ],
        help: {
            before: 'In Client Portal, create an Activity Flex Query including the "Net Asset Value (NAV)" and "Open Positions" sections, then generate a Flex Web Service token under',
            href: 'https://www.ibkrguides.com/clientportal/performanceandstatements/flex3.htm',
            link: 'Performance & Reports → Flex Queries',
        },
    },
];

/** Whether our own integration is still flagged as beta on `today`. */
export function isProviderInBeta(
    provider: ConnectProvider,
    today: Date = new Date(),
): boolean {
    return (
        provider.betaUntil !== undefined &&
        today.toISOString().slice(0, 10) < provider.betaUntil
    );
}

/** The provider's picker entry, carrying its beta flag while it lasts. */
export function providerInstitution(
    provider: ConnectProvider,
): EnableBankingInstitution {
    return isProviderInBeta(provider)
        ? { ...provider.institution, beta: true }
        : provider.institution;
}

/** Find a provider by the selected institution name. */
export function connectProviderForBank(
    name: string | undefined,
): ConnectProvider | undefined {
    return CONNECT_PROVIDERS.find((p) => p.institution.name === name);
}

/** The providers offered when connecting from this country. */
export function providersForCountry(country: string): ConnectProvider[] {
    return CONNECT_PROVIDERS.filter(
        (p) => !p.onlyCountry || p.onlyCountry === country,
    );
}

/** Find a provider by its `banking_connections.provider` value. */
export function connectProviderByKey(
    providerKey: string,
): ConnectProvider | undefined {
    return CONNECT_PROVIDERS.find((p) => p.providerKey === providerKey);
}

/** The credential payload (field values), shared by connect and update. */
export function credentialPayload(
    provider: ConnectProvider,
    values: Record<string, string>,
): Record<string, string> {
    return Object.fromEntries(
        provider.fields.map((f) => [f.key, values[f.key] ?? '']),
    );
}

/** The connect request body for a provider: its credentials, plus the country
 * when it asks for one. */
export function providerConnectBody(
    provider: ConnectProvider,
    values: Record<string, string>,
    country: string,
): Record<string, string> {
    return {
        ...credentialPayload(provider, values),
        ...(provider.sendsCountry ? { country } : {}),
    };
}

/**
 * POST a connect request and hand back where the server wants the browser to
 * go next. Shared by the bank flow and the broker flow, which differ only in
 * the endpoint and the body they send.
 */
export async function postConnectRequest(
    url: string,
    body: Record<string, unknown>,
): Promise<string> {
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
        const failure = await response.json().catch(() => ({}));

        throw new Error(failure.message || 'Failed to start authorization');
    }

    const data = await response.json();

    return data.redirect_url;
}

/** Whether every credential field has been filled in. */
export function isProviderComplete(
    provider: ConnectProvider,
    values: Record<string, string>,
): boolean {
    return provider.fields.every((f) => (values[f.key] ?? '').length > 0);
}

export function ProviderHelp({ help }: { help: ConnectProvider['help'] }) {
    return (
        <div className="space-y-1.5">
            <p className="text-xs text-muted-foreground">
                {__(help.before)}{' '}
                <a
                    href={help.href}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="underline"
                >
                    {__(help.link)}
                </a>
                .
            </p>
            {/* Own paragraph, not muted: these are settings the provider's own
                form gets wrong by default, so they have to survive skimming. */}
            {help.after && (
                <p className="text-xs font-medium">{__(help.after)}</p>
            )}
        </div>
    );
}

/**
 * The element id of one credential input. Exported so a caller that supplies
 * its own label — the onboarding step uses `StepField`, not `Label` — can point
 * it at the right control. `idPrefix` keeps ids unique between surfaces.
 */
export function credentialFieldId(
    idPrefix: string,
    provider: ConnectProvider,
    field: CredentialField,
): string {
    return `${idPrefix}-${provider.providerKey}-${field.key}`;
}

/** One credential control, unlabelled: the label belongs to the surface. */
export function ProviderCredentialInput({
    id,
    field,
    value,
    onChange,
}: {
    id: string;
    field: CredentialField;
    value: string;
    onChange: (value: string) => void;
}) {
    const placeholder =
        field.placeholderExample ??
        (field.placeholder ? __(field.placeholder) : undefined);
    const className = cn(field.mono && 'font-mono', field.small && 'text-xs');

    if (field.type === 'textarea') {
        return (
            <Textarea
                id={id}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                rows={6}
                className={className}
                placeholder={placeholder}
            />
        );
    }

    return (
        <Input
            id={id}
            type={field.type}
            value={value}
            onChange={(e) => onChange(e.target.value)}
            className={className}
            placeholder={placeholder}
        />
    );
}

/**
 * Renders a provider's credential inputs, bound to a flat values record.
 * `idPrefix` keeps element ids unique between the connect and update dialogs.
 */
export function ProviderCredentialFields({
    provider,
    values,
    onChange,
    idPrefix = 'connect',
}: {
    provider: ConnectProvider;
    values: Record<string, string>;
    onChange: (key: string, value: string) => void;
    idPrefix?: string;
}) {
    return (
        <div className="space-y-4">
            {provider.fields.map((field) => {
                const id = credentialFieldId(idPrefix, provider, field);

                return (
                    <div key={field.key} className="space-y-2">
                        <Label htmlFor={id}>{__(field.label)}</Label>
                        <ProviderCredentialInput
                            id={id}
                            field={field}
                            value={values[field.key] ?? ''}
                            onChange={(value) => onChange(field.key, value)}
                        />
                    </div>
                );
            })}
            <ProviderHelp help={provider.help} />
        </div>
    );
}
