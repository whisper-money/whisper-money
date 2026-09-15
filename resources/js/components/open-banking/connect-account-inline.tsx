import { BankLogo } from '@/components/bank-logo';
import { StepButton } from '@/components/onboarding/step-button';
import {
    StepBadge,
    StepCheck,
    StepChevron,
    StepList,
    StepRow,
    StepSectionLabel,
} from '@/components/onboarding/step-list';
import {
    StepError,
    StepNote,
    StepScreen,
    stepControlClass,
} from '@/components/onboarding/step-screen';
import {
    BetaConnectorBadge,
    BetaConnectorNotice,
} from '@/components/open-banking/beta-connector';
import {
    BrokerRows,
    ConnectBrokerInline,
} from '@/components/open-banking/connect-broker-inline';
import { ReplaceConnectionWarning } from '@/components/open-banking/replace-connection-warning';
import { Input } from '@/components/ui/input';
import {
    useConnectCountries,
    useConnectFlow,
    useGuessedCountry,
} from '@/hooks/use-connect-flow';
import { useWebHaptics } from '@/hooks/use-web-haptics';
import { hasLiveConnectionForProvider } from '@/lib/banking-connections';
import {
    providersForCountry,
    type ConnectProvider,
} from '@/lib/connect-providers';
import { captureEvent } from '@/lib/posthog';
import { cn } from '@/lib/utils';
import type {
    BankingConnection,
    EnableBankingInstitution,
} from '@/types/banking';
import { __ } from '@/utils/i18n';
import {
    ChevronDown,
    Eye,
    Lock,
    RefreshCw,
    Search,
    Shield,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

/** A bank a previous attempt failed on, offered as a one-tap retry. */
export interface RetryBank {
    name: string;
    country: string;
}

interface ConnectAccountInlineProps {
    onBack: () => void;
    /** Where "my bank isn't in the list" goes — the hub's manual account form. */
    onManual?: () => void;
    retryBank?: RetryBank | null;
    connections?: BankingConnection[];
}

/** Search input with its magnifier, the one control both list screens share. */
function StepSearch({
    placeholder,
    value,
    onChange,
    autoFocus = false,
}: {
    placeholder: string;
    value: string;
    onChange: (value: string) => void;
    autoFocus?: boolean;
}) {
    return (
        <div className="relative flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-4 size-[18px] -translate-y-1/2 text-muted-foreground" />
            <Input
                placeholder={placeholder}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                autoFocus={autoFocus}
                className={cn(stepControlClass, 'pl-11')}
            />
        </div>
    );
}

export function ConnectAccountInline({
    onBack,
    onManual,
    retryBank = null,
    connections = [],
}: ConnectAccountInlineProps) {
    const guessedCountry = useGuessedCountry();
    // A retry knows which country the failed attempt was in, and it is a fact
    // rather than a guess, so it wins.
    const openOn = retryBank?.country ?? guessedCountry;

    const {
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
        connectedBankNames,
        isAlreadyConnected,
        acknowledgedReplace,
        setAcknowledgedReplace,
        canSubmit,
        fetchInstitutions,
        handleAuthorize,
        clearBankSelection,
    } = useConnectFlow(connections, {
        initialCountry: openOn,
        separateProviders: true,
    });

    /**
     * The broker the user tapped in the section below the banks. It is a screen
     * of its own rather than this one's confirm step: there is no redirect to
     * warn about, and what comes back is a position, not a statement.
     */
    const [broker, setBroker] = useState<ConnectProvider | null>(null);

    const countries = useConnectCountries();
    const [countryQuery, setCountryQuery] = useState('');

    const { trigger } = useWebHaptics();

    const countryName = useMemo(
        () => countries.find((c) => c.code === country)?.name ?? country,
        [countries, country],
    );

    /**
     * The brokers, filtered by the same search box as the banks. They used to be
     * rows in the bank list, so a user who types "Binance" has to keep finding
     * it — having its own section must not make it unsearchable.
     */
    const brokers = useMemo(
        () =>
            providersForCountry(country).filter(
                (p) =>
                    !hasLiveConnectionForProvider(connections, p.providerKey) &&
                    p.institution.name
                        .toLowerCase()
                        .includes(searchQuery.toLowerCase()),
            ),
        [connections, country, searchQuery],
    );

    const filteredCountries = useMemo(
        () =>
            countryQuery
                ? countries.filter((c) =>
                      c.name.toLowerCase().includes(countryQuery.toLowerCase()),
                  )
                : countries,
        [countries, countryQuery],
    );

    /**
     * Carry the user straight back to the bank that just failed, with the real
     * catalogue entry rather than a name we echoed back: only the fetched one
     * carries the logo and the beta flag the handoff screen reads.
     */
    const hasRetried = useRef(false);

    useEffect(() => {
        if (!retryBank || hasRetried.current || institutions.length === 0) {
            return;
        }

        const match = institutions.find(
            (institution) => institution.name === retryBank.name,
        );

        hasRetried.current = true;

        if (match) {
            setSelectedBank(match);
            setStep('confirm');
        }
    }, [retryBank, institutions, setSelectedBank, setStep]);

    const openBank = useCallback(
        (institution: EnableBankingInstitution) => {
            trigger('light');
            setSelectedBank(institution);
            setStep('confirm');
        },
        [trigger, setSelectedBank, setStep],
    );

    const chooseCountry = useCallback(
        (code: string) => {
            trigger('light');
            setCountry(code);
            clearBankSelection();
            setCountryQuery('');
            fetchInstitutions(code);
        },
        [trigger, setCountry, clearBankSelection, fetchInstitutions],
    );

    const handleBack = useCallback(() => {
        trigger('light');

        // Nothing fetched yet means the flow opened on the country list, because
        // the guess found no country we connect in. There is no bank list behind
        // it to go back to, so back means out.
        if (step === 'bank' || institutions.length === 0) {
            onBack();

            return;
        }

        setStep('bank');
    }, [step, institutions.length, onBack, setStep, trigger]);

    /**
     * The last thing that happens inside our app before the bank takes over, and
     * the widest gap in the onboarding funnel: whoever does not come back from
     * the redirect is only visible here. This inline flow is the onboarding one
     * (settings uses the dialog), so the event is named for that surface.
     */
    const startConnect = useCallback(() => {
        captureEvent('onboarding_bank_connect_started', {
            country,
            provider: 'enable_banking',
        });
        handleAuthorize();
    }, [country, handleAuthorize]);

    const back = (
        <StepButton text={__('Back')} variant="ghost" onClick={handleBack} />
    );

    if (broker) {
        return (
            <ConnectBrokerInline
                initialProvider={broker}
                initialCountry={country}
                onBack={() => setBroker(null)}
            />
        );
    }

    if (step === 'country') {
        return (
            <StepScreen
                title={__('Which country is the account in?')}
                description={__(
                    'Not where you live — where the bank is. A Santander account in Spain and one in Portugal are two different connections.',
                )}
                footer={back}
            >
                {error && <StepError>{error}</StepError>}

                <div className="flex flex-col">
                    <StepSearch
                        placeholder={__('Search :count countries', {
                            count: countries.length,
                        })}
                        value={countryQuery}
                        onChange={setCountryQuery}
                        autoFocus
                    />

                    {guessedCountry && !countryQuery && (
                        <>
                            <StepSectionLabel>
                                {__('Guessed from your settings')}
                            </StepSectionLabel>
                            <StepList>
                                <StepRow
                                    title={
                                        countries.find(
                                            (c) => c.code === guessedCountry,
                                        )?.name ?? guessedCountry
                                    }
                                    trailing={
                                        country === guessedCountry ? (
                                            <StepCheck />
                                        ) : (
                                            <StepChevron />
                                        )
                                    }
                                    onClick={() =>
                                        chooseCountry(guessedCountry)
                                    }
                                />
                            </StepList>
                        </>
                    )}

                    <StepSectionLabel>{__('All countries')}</StepSectionLabel>
                    {filteredCountries.length > 0 ? (
                        <StepList className="max-h-[42vh] overflow-y-auto">
                            {filteredCountries.map((option) => (
                                <StepRow
                                    key={option.code}
                                    title={option.name}
                                    trailing={
                                        country === option.code ? (
                                            <StepCheck />
                                        ) : (
                                            <StepChevron />
                                        )
                                    }
                                    onClick={() => chooseCountry(option.code)}
                                />
                            ))}
                        </StepList>
                    ) : (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            {__('No countries found.')}
                        </p>
                    )}
                </div>
            </StepScreen>
        );
    }

    if (step === 'confirm' && selectedBank) {
        return (
            <StepScreen
                icon={
                    <BankLogo
                        src={selectedBank.logo}
                        name={selectedBank.name}
                        fallback="letter"
                        className="size-11 rounded-lg text-base"
                    />
                }
                title={__('You’re about to log in at :bank', {
                    bank: selectedBank.name,
                })}
                description={__(
                    'Their page, their login, the same one you always use. Then you land straight back here.',
                )}
                footer={
                    <>
                        <StepButton
                            text={__('Continue to :bank', {
                                bank: selectedBank.name,
                            })}
                            loading={isSubmitting}
                            loadingText={__('Connecting...')}
                            onClick={startConnect}
                            disabled={!canSubmit}
                        />
                        <StepNote>
                            {__('About 40 seconds. We’ll hold your place.')}
                        </StepNote>
                        {back}
                    </>
                }
            >
                {error && <StepError>{error}</StepError>}

                <StepList>
                    <StepRow
                        icon={Lock}
                        title={__('Your password stays at your bank')}
                        description={__(
                            'It is never typed into Whisper and never reaches us.',
                        )}
                    />
                    <StepRow
                        icon={Eye}
                        title={__('We can read, never touch')}
                        description={__(
                            'The permission you grant cannot move money, even by accident.',
                        )}
                    />
                    <StepRow
                        icon={RefreshCw}
                        title={__('Once now, then it keeps itself current')}
                        description={__(
                            'Twelve months today, and every new movement after.',
                        )}
                    />
                </StepList>

                {selectedBank.beta && <BetaConnectorNotice />}

                {isAlreadyConnected && (
                    <ReplaceConnectionWarning
                        acknowledged={acknowledgedReplace}
                        onAcknowledgedChange={setAcknowledgedReplace}
                    />
                )}
            </StepScreen>
        );
    }

    return (
        <StepScreen
            title={__('Where do you bank?')}
            description={__(
                'You sign in at your bank, not here. Whisper never sees your password, and can never move money.',
            )}
            footer={
                <>
                    {onManual && (
                        <StepButton
                            text={__('My bank isn’t in the list')}
                            variant="outline"
                            onClick={() => {
                                trigger('light');
                                onManual();
                            }}
                        />
                    )}
                    {back}
                </>
            }
        >
            {error && <StepError>{error}</StepError>}

            <div className="flex flex-col">
                {/* Sticky so refining the search stays possible part-way down a
                    300-bank country list. */}
                <div className="sticky top-0 z-10 -mx-1 flex gap-2 bg-background px-1 pb-2">
                    <button
                        type="button"
                        onClick={() => {
                            trigger('light');
                            setStep('country');
                        }}
                        className={cn(
                            stepControlClass,
                            'flex w-[38%] shrink-0 cursor-pointer items-center justify-between gap-2 border bg-transparent px-3.5 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                        )}
                    >
                        <span className="truncate">
                            {countryName || __('Country')}
                        </span>
                        <ChevronDown className="size-[18px] shrink-0 text-muted-foreground" />
                    </button>

                    <StepSearch
                        placeholder={
                            isLoading
                                ? __('Loading banks...')
                                : __('Search :count banks', {
                                      count: institutions.length,
                                  })
                        }
                        value={searchQuery}
                        onChange={setSearchQuery}
                    />
                </div>

                {filteredInstitutions.length > 0 ? (
                    // ~300 banks in some countries; 35vh keeps the last rows
                    // clear of the pinned footer on short phones.
                    <StepList className="max-h-[35vh] overflow-y-auto">
                        {filteredInstitutions.map((institution, index) => (
                            <StepRow
                                key={`${institution.name}-${institution.country}-${index}`}
                                leading={
                                    <BankLogo
                                        src={institution.logo}
                                        name={institution.name}
                                        fallback="letter"
                                        className="size-7 rounded-md text-xs"
                                    />
                                }
                                title={institution.name}
                                badge={
                                    institution.beta ? (
                                        <BetaConnectorBadge />
                                    ) : undefined
                                }
                                trailing={
                                    connectedBankNames.has(institution.name) ? (
                                        <StepBadge>
                                            {__('Already connected')}
                                        </StepBadge>
                                    ) : (
                                        <StepChevron />
                                    )
                                }
                                onClick={() => openBank(institution)}
                            />
                        ))}
                    </StepList>
                ) : (
                    <p className="py-6 text-center text-sm text-muted-foreground">
                        {isLoading
                            ? __('Loading banks...')
                            : __('No banks found.')}
                    </p>
                )}

                {brokers.length > 0 && (
                    <>
                        <StepSectionLabel>
                            {__('Brokers and exchanges')}
                        </StepSectionLabel>
                        {/* Folded after the first two while the user is
                            searching for a bank, and opened whole by a search
                            that has already narrowed the list. */}
                        <BrokerRows
                            providers={brokers}
                            collapsedAfter={searchQuery ? undefined : 2}
                            onSelect={(provider) => {
                                trigger('light');
                                setBroker(provider);
                            }}
                        />
                    </>
                )}

                <p className="flex items-start gap-2.5 pt-4 text-[13px] leading-normal text-pretty text-muted-foreground">
                    <Shield className="mt-0.5 size-4 shrink-0" />
                    {__(
                        'Through regulated open banking (PSD2), via a licensed provider. Revoke it from your bank or from Whisper whenever you like.',
                    )}
                </p>
            </div>
        </StepScreen>
    );
}
