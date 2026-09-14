import { BankLogo } from '@/components/bank-logo';
import { StepButton } from '@/components/onboarding/step-button';
import { StepConnectFailed } from '@/components/onboarding/step-connect-failed';
import {
    StepCheck,
    StepChevron,
    StepList,
    StepRow,
    StepSectionLabel,
} from '@/components/onboarding/step-list';
import { StepManualAccount } from '@/components/onboarding/step-manual-account';
import {
    StepMapAccounts,
    type PendingMapping,
} from '@/components/onboarding/step-map-accounts';
import {
    StepCallout,
    StepEmphasis,
    StepNote,
    StepScreen,
} from '@/components/onboarding/step-screen';
import {
    ConnectAccountInline,
    type RetryBank,
} from '@/components/open-banking/connect-account-inline';
import { ConnectBrokerInline } from '@/components/open-banking/connect-broker-inline';
import { useCheapestMonthlyPrice } from '@/hooks/use-cheapest-monthly-price';
import { CreatedAccount } from '@/hooks/use-onboarding-state';
import { captureEvent } from '@/lib/posthog';
import { type SharedData } from '@/types';
import { formatAccountType, type AccountType } from '@/types/account';
import { type SignupPlan } from '@/types/pricing';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import {
    ChartLine,
    House,
    Landmark,
    Plus,
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

/**
 * The hub, and the two ways out of it. Neither way out is a step of its own:
 * they are modes of this one, which is what keeps the progress bar on 6 of 11
 * for everything hanging off the hub — the same arrangement `SUB_STEPS` gives
 * `import-transactions` and `import-balances`.
 */
type HubMode = 'hub' | 'manual' | 'connected' | 'broker' | 'failed';

/**
 * The bank a failed authorization named, off the URL
 * `AuthorizationController::finishWithError` sends the user back with. The
 * connection row is deleted by then, so the URL is the only place left that
 * knows which bank refused.
 */
function readFailedBank(): RetryBank | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const params = new URLSearchParams(window.location.search);
    const name = params.get('connect_error');
    const country = params.get('connect_country');

    // Both or neither: without the country there is no retry to offer, and the
    // country is half of what identifies a bank to the provider.
    return name && country ? { name, country } : null;
}

/** Drop the failure off the URL so a reload does not replay a dealt-with one. */
function clearFailedBank(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);
    url.searchParams.delete('connect_error');
    url.searchParams.delete('connect_country');
    window.history.replaceState(window.history.state, '', url.toString());
}

/** One account row, however it reached the screen. */
interface AccountLine {
    id: string;
    name: string;
    bankName: string;
    bankLogo: string | null;
    meta: string;
    connected: boolean;
}

interface AccountGroup {
    bankName: string;
    bankLogo: string | null;
    /** Said once for the group: only a connected bank keeps itself current. */
    connected: boolean;
    accounts: AccountLine[];
}

/** Where a row of the hub leads. Only 'manual' asks the user to type. */
type HubRoute = 'manual' | 'connected' | 'broker';

/** One row of the section that asks for what open banking never returns. */
interface HubSuggestion {
    key: string;
    icon: LucideIcon;
    title: string;
    description: string;
    route: HubRoute;
}

export interface ExistingAccount {
    id: string;
    name: string;
    name_iv: string | null;
    encrypted: boolean;
    type: AccountType;
    currency_code: string;
    bank_id: string;
    banking_connection_id: string | null;
    bank?: {
        id: string;
        name: string;
        logo: string | null;
    };
}

interface StepAccountsHubProps {
    banks: { id: string; name: string; logo: string | null }[];
    isFirstAccount: boolean;
    existingAccounts?: ExistingAccount[];
    createdAccounts?: CreatedAccount[];
    hasSelectedConnectedAccount?: boolean;
    signupPlan?: SignupPlan | null;
    /** A connection waiting for the user to say which of its accounts to keep. */
    pendingMapping?: PendingMapping | null;
    onAccountCreated: (account: CreatedAccount) => void;
    onConnectedAccountSelected?: () => void;
    onContinue?: () => void;
}

/**
 * Step 6: the hub the whole of account setup returns to.
 *
 * Open banking returns current accounts and cards, and nothing else — no
 * pension, no broker, no mortgage. So this is not a fork picked once, it is a
 * place the user comes back to: both ways out land here again, and the step
 * ends when they say they are done rather than when they have done one thing.
 *
 * With accounts already in, the screen spends itself asking for what is
 * missing. That is the step's real work: nobody adds a mortgage unprompted, and
 * it is where most of their money is.
 */
export function StepAccountsHub({
    banks,
    isFirstAccount,
    existingAccounts = [],
    createdAccounts = [],
    hasSelectedConnectedAccount = false,
    signupPlan = null,
    pendingMapping = null,
    onAccountCreated,
    onConnectedAccountSelected,
    onContinue,
}: StepAccountsHubProps) {
    const { pricing, subscriptionsEnabled, locale, flash } =
        usePage<SharedData>().props;
    const cheapestMonthlyPrice = useCheapestMonthlyPrice();
    // Someone who signed up from the free card gets no bank connections, so an
    // empty hub would have a single row on it: open the manual form instead.
    const isFreePlan = signupPlan === 'free';
    const hasAccounts =
        createdAccounts.length > 0 || existingAccounts.length > 0;
    const [failedBank] = useState(readFailedBank);
    const [mode, setMode] = useState<HubMode>(() => {
        if (failedBank) {
            return 'failed';
        }

        return isFreePlan && !hasAccounts ? 'manual' : 'hub';
    });

    const [retryBank, setRetryBank] = useState<RetryBank | null>(null);

    /** Every exit from the failure screen, so none of them leaves it on the URL. */
    const leaveFailure = useCallback((next: HubMode) => {
        clearFailedBank();
        setMode(next);
    }, []);

    // Shown until the user has committed to a connected account once; after
    // that repeating the price on every extra account is just noise. The
    // commitment sentence never depends on the price: with no plans configured
    // the row would otherwise sell a paid feature with no disclosure at all.
    const connectedPlanNotice = useMemo(() => {
        if (
            !subscriptionsEnabled ||
            signupPlan === 'paid' ||
            hasSelectedConnectedAccount
        ) {
            return undefined;
        }

        return (
            <>
                {cheapestMonthlyPrice !== null && (
                    <>
                        {__('Standard plan, from :price/month.', {
                            price: formatCurrency(
                                cheapestMonthlyPrice * 100,
                                pricing.currency,
                                locale,
                            ),
                        })}{' '}
                    </>
                )}
                {__("You'll choose a plan at the end of the onboarding.")}
            </>
        );
    }, [
        subscriptionsEnabled,
        signupPlan,
        hasSelectedConnectedAccount,
        cheapestMonthlyPrice,
        pricing.currency,
        locale,
    ]);

    // Created and existing accounts render as one grouped list. They overlap
    // rather than exclude each other — the step polls for accounts finalized in
    // another browser while the user adds more here — so the id decides which
    // rows there are, not which of the two arrays happens to be non-empty.
    const accountGroups = useMemo((): AccountGroup[] => {
        const lines = new Map<string, AccountLine>();

        for (const account of existingAccounts) {
            lines.set(account.id, {
                id: account.id,
                name: account.name || __('Account'),
                bankName: account.bank?.name ?? __('No bank'),
                bankLogo: account.bank?.logo ?? null,
                meta: `${formatAccountType(account.type)} · ${account.currency_code}`,
                connected: account.banking_connection_id !== null,
            });
        }

        for (const account of createdAccounts) {
            lines.set(account.id, {
                id: account.id,
                name: account.name,
                bankName: account.bankName ?? __('No bank'),
                bankLogo: account.bankLogo ?? null,
                meta: `${formatAccountType(account.type)} · ${account.currencyCode}`,
                connected: account.connected ?? false,
            });
        }

        const groups = new Map<string, AccountGroup>();

        for (const line of lines.values()) {
            const group = groups.get(line.bankName) ?? {
                bankName: line.bankName,
                bankLogo: line.bankLogo,
                connected: false,
                accounts: [],
            };
            group.accounts.push(line);
            group.connected = group.connected || line.connected;
            groups.set(line.bankName, group);
        }

        return Array.from(groups.values());
    }, [createdAccounts, existingAccounts]);

    const accountCount = accountGroups.reduce(
        (total, group) => total + group.accounts.length,
        0,
    );

    /**
     * Which way out was taken is the one thing `onboarding_step_viewed` cannot
     * say — the hub and everything behind it are a single step — and it is the
     * question this screen exists to ask. `accounts` tells the two states apart.
     */
    const openRoute = useCallback(
        (route: HubRoute, option: string) => {
            captureEvent('onboarding_accounts_hub_route', {
                option,
                accounts: accountCount,
            });

            if (route !== 'manual') {
                onConnectedAccountSelected?.();
            }

            setMode(route);
        },
        [accountCount, onConnectedAccountSelected],
    );

    const suggestions = useMemo((): HubSuggestion[] => {
        const all: HubSuggestion[] = [
            {
                key: 'mortgage',
                icon: House,
                title: __('A mortgage or a loan'),
                description: __('By hand — no bank shares these'),
                route: 'manual',
            },
            {
                key: 'investment',
                icon: ChartLine,
                title: __('A pension or a broker'),
                description: __('Indexa and Coinbase connect with a token'),
                // A broker is connected, not typed: it has its own list and its
                // own form, because open banking is not what any of them speak.
                route: 'broker',
            },
            {
                key: 'bank',
                icon: Landmark,
                title: __('Another bank'),
                description: __('A second account, a joint one, one abroad'),
                // The only row whose wording promises no connection, so it is
                // the one that survives having no connections to offer.
                route: isFreePlan ? 'manual' : 'connected',
            },
        ];

        return all.filter(
            (suggestion) => !isFreePlan || suggestion.route === 'manual',
        );
    }, [isFreePlan]);

    /** The plan disclosure belongs above the first commitment, and only there. */
    const firstConnectKey = suggestions.find(
        (suggestion) => suggestion.route !== 'manual',
    )?.key;

    // Only from the hub itself. The step polls for connections finished in
    // another browser, so a mapping can arrive at any moment — and swapping the
    // screen out from under a half-filled manual form would throw the form away.
    // It is still there when they come back.
    if (pendingMapping && mode === 'hub') {
        return <StepMapAccounts pending={pendingMapping} />;
    }

    // The bank flow renders its own StepScreen so each of its sub-steps gets
    // the pinned action footer. It comes back to the hub, not out of the step.
    if (mode === 'connected') {
        // The retry target belongs to this visit to the flow only. Leaving it
        // set would send the next plain "Connect a bank" straight back to the
        // bank that failed, with no list in between.
        const leaveConnect = (next: HubMode) => {
            setRetryBank(null);
            setMode(next);
        };

        return (
            <ConnectAccountInline
                onBack={() => leaveConnect('hub')}
                onManual={() => leaveConnect('manual')}
                retryBank={retryBank}
            />
        );
    }

    if (mode === 'broker') {
        return (
            <ConnectBrokerInline
                onBack={() => setMode('hub')}
                onManual={() => setMode('manual')}
            />
        );
    }

    if (mode === 'failed' && failedBank) {
        return (
            <StepConnectFailed
                bankName={failedBank.name}
                message={flash?.error}
                onRetry={() => {
                    setRetryBank(failedBank);
                    leaveFailure('connected');
                }}
                onDifferentBank={() => leaveFailure('connected')}
                onManual={() => leaveFailure('manual')}
            />
        );
    }

    if (mode === 'manual') {
        return (
            <StepManualAccount
                banks={banks}
                isFirstAccount={isFirstAccount}
                onAccountCreated={onAccountCreated}
                // A free signup with nothing yet skipped the hub, so there is
                // genuinely nowhere to go back to — and the header offers no
                // arrow on this step either.
                onBack={
                    !isFreePlan || hasAccounts
                        ? () => setMode('hub')
                        : undefined
                }
            />
        );
    }

    // Nothing in yet: the two ways in, and why most people need both.
    if (accountGroups.length === 0) {
        return (
            <StepScreen
                title={__("Let's build the picture")}
                description={__(
                    "Start with the bank your salary lands in. You'll add the rest right after — you can mix as many as you like.",
                )}
            >
                <div className="flex flex-col gap-6">
                    <StepList>
                        <StepRow
                            icon={Landmark}
                            title={__('Connect a bank')}
                            description={__(
                                'Twelve months of movements, read-only, about forty seconds. Keeps itself current afterwards.',
                            )}
                            meta={connectedPlanNotice}
                            trailing={<StepChevron />}
                            onClick={() => openRoute('connected', 'connect')}
                        />
                        <StepRow
                            icon={Plus}
                            title={__('Add one myself')}
                            description={__(
                                "A file to import, or just what it's worth today. For anything a bank won't hand over.",
                            )}
                            trailing={<StepChevron />}
                            onClick={() => openRoute('manual', 'manual')}
                        />
                    </StepList>

                    <StepCallout>
                        <StepEmphasis
                            sentence={__(
                                'Most people end up with :both: a bank for the day-to-day, and a couple added by hand for the mortgage and the pension. Open banking almost never returns those.',
                            )}
                            word={__('both')}
                        />
                    </StepCallout>
                </div>
            </StepScreen>
        );
    }

    // Accounts in: the list is the receipt, and the section under it is the
    // screen's real work. This is also what a resumed signup and a return from
    // the bank land on, so it is the ordinary state, not the exception.
    return (
        <StepScreen
            title={__(":count in. What's missing?", { count: accountCount })}
            description={__(
                'Current accounts and cards come from the bank. Pensions and brokers need their own key, and mortgages nobody shares at all — but that is where most of your money sits.',
            )}
            footer={
                <>
                    <StepButton
                        text={__("That's everything — continue")}
                        onClick={onContinue}
                    />
                    <StepNote>
                        {__('You can add more at any time from Settings.')}
                    </StepNote>
                </>
            }
        >
            <div className="flex flex-col gap-1">
                {accountGroups.map((group) => (
                    <div key={group.bankName}>
                        <StepSectionLabel>
                            <BankLogo
                                src={group.bankLogo}
                                name={group.bankName}
                                fallback="letter"
                                className="size-5 rounded-sm text-[9.5px]"
                            />
                            {group.connected
                                ? __(':bank · syncing daily', {
                                      bank: group.bankName,
                                  })
                                : group.bankName}
                        </StepSectionLabel>
                        <StepList>
                            {group.accounts.map((account) => (
                                <StepRow
                                    key={account.id}
                                    title={account.name}
                                    description={account.meta}
                                    trailing={<StepCheck muted />}
                                />
                            ))}
                        </StepList>
                    </div>
                ))}

                <StepSectionLabel>{__('Usually missed')}</StepSectionLabel>
                <StepList>
                    {suggestions.map((suggestion) => (
                        <StepRow
                            key={suggestion.key}
                            icon={suggestion.icon}
                            title={suggestion.title}
                            description={suggestion.description}
                            meta={
                                suggestion.key === firstConnectKey
                                    ? connectedPlanNotice
                                    : undefined
                            }
                            trailing={<StepChevron />}
                            onClick={() =>
                                openRoute(suggestion.route, suggestion.key)
                            }
                        />
                    ))}
                </StepList>
            </div>
        </StepScreen>
    );
}
