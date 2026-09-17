import { BankLogo } from '@/components/bank-logo';
import { StepButton } from '@/components/onboarding/step-button';
import {
    StepChevron,
    StepList,
    StepRow,
} from '@/components/onboarding/step-list';
import {
    StepCallout,
    StepEmphasis,
    StepError,
    StepField,
    stepFormClass,
    StepNote,
    StepScreen,
} from '@/components/onboarding/step-screen';
import {
    CONNECT_COUNTRY_CODES,
    useGuessedCountry,
} from '@/hooks/use-connect-flow';
import { useWebHaptics } from '@/hooks/use-web-haptics';
import {
    credentialFieldId,
    isProviderComplete,
    postConnectRequest,
    providerConnectBody,
    ProviderCredentialInput,
    providersForCountry,
    type ConnectProvider,
} from '@/lib/connect-providers';
import { leavePage } from '@/lib/leave-page';
import { captureEvent } from '@/lib/posthog';
import { __ } from '@/utils/i18n';
import { Key, Lock } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

/** What a provider asks for, said before the user commits to a screen of it. */
function providerMeta(provider: ConnectProvider): string {
    const key = __(provider.fields[0].label);

    return provider.sendsTransactions
        ? __(':key · balance and movements', { key })
        : __(':key · holdings, not movements', { key });
}

/**
 * The providers, as rows. Shown whole on the broker screen, and folded after
 * the first couple inside the bank picker, where they are an aside to the
 * search rather than the point of the screen.
 */
export function BrokerRows({
    providers,
    collapsedAfter,
    onSelect,
}: {
    providers: ConnectProvider[];
    collapsedAfter?: number;
    onSelect: (provider: ConnectProvider) => void;
}) {
    const [expanded, setExpanded] = useState(false);
    const folded =
        collapsedAfter !== undefined &&
        !expanded &&
        providers.length > collapsedAfter + 1;

    const shown = folded ? providers.slice(0, collapsedAfter) : providers;
    const rest = folded ? providers.slice(collapsedAfter) : [];

    return (
        <StepList>
            {shown.map((provider) => (
                <StepRow
                    key={provider.providerKey}
                    leading={
                        <BankLogo
                            src={provider.institution.logo}
                            name={provider.institution.name}
                            fallback="letter"
                            className="size-7 rounded-md text-xs"
                        />
                    }
                    title={provider.institution.name}
                    description={providerMeta(provider)}
                    trailing={<StepChevron />}
                    onClick={() => onSelect(provider)}
                />
            ))}

            {rest.length > 0 && (
                <StepRow
                    leading={
                        <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-muted text-xs font-medium text-muted-foreground">
                            +{rest.length}
                        </span>
                    }
                    title={rest
                        .map((provider) => provider.institution.name)
                        .join(', ')}
                    trailing={<StepChevron />}
                    onClick={() => setExpanded(true)}
                />
            )}
        </StepList>
    );
}

interface ConnectBrokerInlineProps {
    onBack: () => void;
    /**
     * Opens straight on this provider's form. The bank picker's broker section
     * has already asked which one, so its rows skip the list.
     */
    initialProvider?: ConnectProvider | null;
    /**
     * The country to report the connection under. The bank picker has one the
     * user chose; the hub opens here with no picker in front of it, so the
     * guess stands in.
     */
    initialCountry?: string | null;
    /** Where "mine isn't here" goes — the hub's manual account form. */
    onManual?: () => void;
}

/**
 * The broker path: pick a provider, hand over a read-only key, done.
 *
 * It is not the bank path with a different form. There is no redirect and no
 * bank login, so none of the handoff's reassurance applies; and what comes back
 * is a position rather than a statement, which the screen has to say out loud
 * or the user waits for transactions that are never coming.
 */
export function ConnectBrokerInline({
    onBack,
    initialProvider = null,
    initialCountry = null,
    onManual,
}: ConnectBrokerInlineProps) {
    const [provider, setProvider] = useState<ConnectProvider | null>(
        initialProvider,
    );
    const [credentials, setCredentials] = useState<Record<string, string>>({});
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const { trigger } = useWebHaptics();

    // `aspsp_country` is a label on a global exchange rather than part of its
    // identity — Wise hardcodes 'GB' server-side for the same reason — so the
    // guess needs no fallback beyond the country most of our users connect in.
    const guessedCountry = useGuessedCountry();
    const country =
        initialCountry || guessedCountry || CONNECT_COUNTRY_CODES[0];

    const providers = useMemo(() => providersForCountry(country), [country]);

    const openProvider = useCallback(
        (next: ConnectProvider) => {
            trigger('light');
            setCredentials({});
            setError(null);
            setProvider(next);
        },
        [trigger],
    );

    const back = useCallback(() => {
        trigger('light');

        // Arriving with a provider already chosen means the list belongs to the
        // screen behind this one, so there is nothing of ours to go back to.
        if (initialProvider || !provider) {
            onBack();

            return;
        }

        setProvider(null);
    }, [initialProvider, provider, onBack, trigger]);

    const connect = useCallback(async () => {
        if (!provider) {
            return;
        }

        setIsSubmitting(true);
        setError(null);

        // The same event the bank path reports, so the funnel counts every
        // connection started in the onboarding whichever door it came through.
        captureEvent('onboarding_bank_connect_started', {
            country,
            provider: provider.providerKey,
        });

        try {
            leavePage(
                await postConnectRequest(
                    provider.endpoint,
                    providerConnectBody(provider, credentials, country),
                ),
            );
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : __('Failed to connect. Please try again.'),
            );
            setIsSubmitting(false);
        }
    }, [provider, credentials, country]);

    const backButton = (
        <StepButton text={__('Back')} variant="ghost" onClick={back} />
    );

    if (!provider) {
        return (
            <StepScreen
                title={__('Which broker or fund?')}
                description={__(
                    'Each one connects with a read-only key of its own. Pick yours and we’ll say where to find it.',
                )}
                footer={
                    <>
                        {onManual && (
                            <StepButton
                                text={__('Mine isn’t in the list')}
                                variant="outline"
                                onClick={() => {
                                    trigger('light');
                                    onManual();
                                }}
                            />
                        )}
                        {backButton}
                    </>
                }
            >
                <BrokerRows providers={providers} onSelect={openProvider} />
            </StepScreen>
        );
    }

    const name = provider.institution.name;

    return (
        <StepScreen
            title={__('Connect :bank', { bank: name })}
            description={__(
                'These don’t use open banking. They give you a read-only key instead — same idea, different door.',
            )}
            footer={
                <>
                    <StepButton
                        text={__('Connect')}
                        loading={isSubmitting}
                        loadingText={__('Connecting...')}
                        disabled={!isProviderComplete(provider, credentials)}
                        onClick={connect}
                    />
                    <StepNote>
                        {__(
                            'Stored encrypted. Revoke it from :bank whenever you like.',
                            { bank: name },
                        )}
                    </StepNote>
                    {backButton}
                </>
            }
        >
            {error && <StepError>{error}</StepError>}

            <div className={`flex flex-col gap-5 ${stepFormClass}`}>
                {provider.fields.map((field) => {
                    const id = credentialFieldId('broker', provider, field);

                    return (
                        <StepField
                            key={field.key}
                            label={__(field.label)}
                            htmlFor={id}
                        >
                            <ProviderCredentialInput
                                id={id}
                                field={field}
                                value={credentials[field.key] ?? ''}
                                onChange={(value) =>
                                    setCredentials((current) => ({
                                        ...current,
                                        [field.key]: value,
                                    }))
                                }
                            />
                        </StepField>
                    );
                })}

                <StepList>
                    <StepRow
                        icon={Key}
                        title={__('Where to find it')}
                        description={__(provider.help.link)}
                        meta={
                            provider.help.after
                                ? __(provider.help.after)
                                : undefined
                        }
                        trailing={<StepChevron />}
                        onClick={() =>
                            window.open(
                                provider.help.href,
                                '_blank',
                                'noopener,noreferrer',
                            )
                        }
                    />
                    <StepRow
                        icon={Lock}
                        title={__('Read-only, always')}
                        description={__(
                            'The key we ask for cannot trade or withdraw.',
                        )}
                    />
                </StepList>

                <StepCallout>
                    {provider.sendsTransactions ? (
                        __(
                            'Your balance lands in your net worth, and your movements land with the rest of your spending.',
                        )
                    ) : (
                        <StepEmphasis
                            sentence={__(
                                'Your positions and their value land in your net worth. :none — brokers report holdings, not purchases, so this account moves as one number.',
                            )}
                            word={__('No transactions')}
                        />
                    )}
                </StepCallout>
            </div>
        </StepScreen>
    );
}
