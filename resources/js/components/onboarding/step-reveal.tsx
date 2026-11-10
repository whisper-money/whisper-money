import { StepButton } from '@/components/onboarding/step-button';
import { StepImportQuickExpenses } from '@/components/onboarding/step-import-quick-expenses';
import {
    StepList,
    StepRow,
    StepSectionLabel,
} from '@/components/onboarding/step-list';
import {
    StepCallout,
    StepEmphasis,
    StepFilled,
    StepScreen,
} from '@/components/onboarding/step-screen';
import { Skeleton } from '@/components/ui/skeleton';
import { useLocale } from '@/hooks/use-locale';
import { captureEvent } from '@/lib/posthog';
import { reveal } from '@/routes/onboarding';
import { type UUID } from '@/types/uuid';
import {
    formatCurrency,
    getCurrencySymbol,
    toMajorUnits,
} from '@/utils/currency';
import { __ } from '@/utils/i18n';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

/** One merchant named on the screen. */
interface RevealMerchant {
    name: string;
    amount: number;
}

/** What the user spent, for the user who has movements to spend them. */
interface SpendingReveal {
    variant: 'spending';
    currency_code: string;
    /** The revealed month, `YYYY-MM`. */
    month: string;
    is_last_month: boolean;
    /** The revealed month is the one still running, so it is not comparable. */
    is_partial: boolean;
    /** Minor units, positive. */
    spent: number;
    merchants: RevealMerchant[];
    /** Distinct merchants the next step has to turn into categories. */
    merchant_count: number;
    /** Merchants that charged the same amount three months running. */
    recurring_count: number;
    /** Every outgoing of the month is a round figure the user typed in. */
    is_estimate: boolean;
    /** Those figures, biggest first. Only on an estimated month. */
    estimates?: RevealMerchant[];
}

/** What the user is worth, for the user who brought no movements at all. */
interface AssetsReveal {
    variant: 'assets';
    currency_code: string;
    /**
     * The reader holds a current account, a card or a savings account — so the
     * missing spending is a month we could not read, not a kind of account they
     * never added. Both land here; only one of them should be offered a current
     * account they already have.
     */
    has_spending_accounts: boolean;
    /**
     * A hand-kept account last month can be typed into, so the reader whose
     * movements gave us no month gets a second way to one.
     */
    quick_expenses_account: {
        id: UUID;
        name: string;
        currency_code: string;
    } | null;
    net_worth: number;
    accounts: {
        id: string;
        name: string;
        connected: boolean;
        balance: number;
    }[];
}

type RevealData = SpendingReveal | AssetsReveal;

/** Under two, the sentence about repeat charges has no plural to stand on. */
const MIN_RECURRING = 2;

interface StepRevealProps {
    /** The user's own guess, in minor units. Absent on a resumed run. */
    spendingGuess?: number;
    onContinue: () => void;
    /** Back to the accounts hub, for the user with no spending to show. */
    onAddAccount: () => void;
}

/**
 * Step 7, and the reason the flow opens with questions instead of a bank
 * picker. The user bet on what they spent last month; this is the answer.
 *
 * It cannot show a breakdown by category — nothing is categorized until step 8
 * has written its rules and the batch behind it has run — so it shows merchants
 * instead. That is not a consolation prize: the name is on the raw row already,
 * and it sets up the next step, which is 147 merchants becoming categories.
 */
export function StepReveal({
    spendingGuess,
    onContinue,
    onAddAccount,
}: StepRevealProps) {
    const [data, setData] = useState<RevealData | null>(null);
    // Typing last month in from the assets variant swaps the screen for the
    // quick expenses one, and coming back asks for the reveal again: a month
    // of estimates turns it into a spending one.
    const [addingExpenses, setAddingExpenses] = useState(false);
    const [reloads, setReloads] = useState(0);
    const onContinueRef = useRef(onContinue);
    onContinueRef.current = onContinue;

    useEffect(() => {
        let cancelled = false;

        axios
            .get<RevealData>(reveal().url)
            .then(({ data }) => {
                if (cancelled) {
                    return;
                }

                // Someone who reached here having added nothing at all has no
                // net worth worth printing either. Nothing to reveal is not a
                // reason to hold them on a screen about it.
                if (data.variant === 'assets' && data.accounts.length === 0) {
                    onContinueRef.current();
                    return;
                }

                setData(data);
                captureEvent('onboarding_reveal_shown', {
                    variant: data.variant,
                    guessed: spendingGuess ?? null,
                    spent: data.variant === 'spending' ? data.spent : null,
                    merchants:
                        data.variant === 'spending'
                            ? data.merchant_count
                            : null,
                    is_estimate:
                        data.variant === 'spending' && data.is_estimate,
                });
            })
            .catch(() => {
                // The reveal is the one screen with nothing of its own to say
                // when the numbers behind it fail to arrive. Moving on beats
                // holding the user in front of an apology.
                if (!cancelled) {
                    onContinueRef.current();
                }
            });

        return () => {
            cancelled = true;
        };
    }, [spendingGuess, reloads]);

    if (data === null) {
        return <RevealSkeleton />;
    }

    if (data.variant === 'spending') {
        return data.is_estimate ? (
            <RevealEstimate
                data={data}
                spendingGuess={spendingGuess}
                onContinue={onContinue}
            />
        ) : (
            <RevealSpending
                data={data}
                spendingGuess={spendingGuess}
                onContinue={onContinue}
            />
        );
    }

    const account = data.quick_expenses_account;

    if (addingExpenses && account) {
        return (
            <StepImportQuickExpenses
                accountId={account.id}
                currencyCode={account.currency_code}
                spendingGuess={spendingGuess}
                onBack={() => setAddingExpenses(false)}
                onComplete={() => {
                    setAddingExpenses(false);
                    setData(null);
                    setReloads((count) => count + 1);
                }}
                source="reveal"
            />
        );
    }

    return (
        <RevealAssets
            data={data}
            spendingGuess={spendingGuess}
            onContinue={onContinue}
            onAddAccount={onAddAccount}
            onAddExpenses={() => setAddingExpenses(true)}
        />
    );
}

/** The shape of the screen, while the numbers that fill it are counted. */
function RevealSkeleton() {
    return (
        <StepScreen>
            <div className="flex flex-col gap-7 pt-1">
                <div className="flex flex-col gap-3.5">
                    <Skeleton className="h-3.5 w-24" />
                    <Skeleton className="h-14 w-52" />
                    <Skeleton className="h-4 w-full" />
                    <Skeleton className="h-4 w-4/5" />
                </div>
                <div className="flex flex-col gap-4">
                    {[0, 1, 2].map((row) => (
                        <div key={row} className="flex flex-col gap-2">
                            <Skeleton className="h-4 w-40" />
                            <Skeleton className="h-1.5 w-full rounded-full" />
                        </div>
                    ))}
                </div>
            </div>
        </StepScreen>
    );
}

function RevealSpending({
    data,
    spendingGuess,
    onContinue,
}: {
    data: SpendingReveal;
    spendingGuess?: number;
    onContinue: () => void;
}) {
    const locale = useLocale();
    const { currency_code: currency } = data;
    const money = (amount: number) =>
        formatCurrency(amount, currency, locale, 0, 0);

    const biggest = data.merchants[0]?.amount ?? 0;

    return (
        <StepScreen
            footer={
                <StepButton
                    text={
                        data.merchant_count > 0
                            ? __('Sort my merchants')
                            : __('Continue')
                    }
                    onClick={onContinue}
                />
            }
        >
            <div className="flex flex-col gap-6.5">
                <div className="flex flex-col gap-3.5">
                    <StepSectionLabel>
                        {sectionLabel(data, locale)}
                    </StepSectionLabel>

                    <h1 className="flex items-baseline gap-1.5">
                        <span className="text-[34px] leading-none font-medium text-muted-foreground">
                            {getCurrencySymbol(currency)}
                        </span>
                        <span className="text-[62px] leading-none font-semibold tracking-[-0.04em] tabular-nums">
                            {new Intl.NumberFormat(locale, {
                                maximumFractionDigits: 0,
                            }).format(toMajorUnits(data.spent, currency))}
                        </span>
                    </h1>

                    <p className="text-[17px] leading-normal text-pretty">
                        {data.is_partial ? (
                            __(
                                'The month isn’t over, so there’s nothing to set your guess against yet — this is everything that has left the account so far.',
                            )
                        ) : (
                            <WhatLeft guess={spendingGuess} money={money} />
                        )}
                    </p>
                </div>

                {data.merchants.length > 0 && (
                    <div className="flex flex-col border-t">
                        <StepSectionLabel>
                            {__('Who you paid most')}
                        </StepSectionLabel>

                        <div className="flex flex-col gap-2.5 pt-1 pb-4">
                            {data.merchants.map((merchant) => (
                                <div
                                    key={merchant.name}
                                    className="flex flex-col gap-1.5"
                                >
                                    <div className="flex items-baseline justify-between gap-3">
                                        <span className="truncate text-[15px] font-medium">
                                            {merchant.name}
                                        </span>
                                        <span className="text-[15px] font-semibold tabular-nums">
                                            {money(merchant.amount)}
                                        </span>
                                    </div>
                                    <div className="h-1.5 rounded-full bg-muted">
                                        <div
                                            className="h-1.5 rounded-full bg-foreground"
                                            style={{
                                                width: `${biggest > 0 ? (merchant.amount / biggest) * 100 : 0}%`,
                                            }}
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                <Closing data={data} />
            </div>
        </StepScreen>
    );
}

/**
 * What the screen leaves the user with: the one observation the raw rows can
 * prove on their own, and the step it hands them to.
 */
function Closing({ data }: { data: SpendingReveal }) {
    const repeats = data.recurring_count >= MIN_RECURRING;

    return (
        <p className="border-t pt-4 text-sm leading-normal text-pretty text-muted-foreground">
            {repeats && (
                <span className="font-medium text-foreground">
                    {__(
                        ':count merchants charged you the same amount in each of the last three months.',
                        { count: data.recurring_count },
                    )}{' '}
                </span>
            )}
            {handover(data.merchant_count, repeats)}
        </p>
    );
}

/**
 * The sentence that points at step 8. It can only call out the repeat charges
 * when the sentence before it actually named some.
 *
 * It used to promise the whole pile by name — "all 16 of them" — and the step
 * behind it then asks for five and lets the reader stop there. Counting them
 * out set up a wall that is not there, so the handover names the work without
 * pricing it.
 */
function handover(merchants: number, repeats: boolean): string {
    if (merchants === 0) {
        return __('Next we sort what came in into categories.');
    }

    return repeats
        ? __(
              'Next we turn them into categories, and the repeat charges are where to start.',
          )
        : __('Next we turn them into categories.');
}

/**
 * What the number is, said plainly, with the guess beside it.
 *
 * Nothing is categorized when this screen runs — that is step 8 — so the figure
 * is every outgoing of the month, and money the user moved to their own savings
 * is in it. Measured on live data, internal movements are about a third of what
 * leaves an account, and for half of users more than 30% of it.
 *
 * This used to read the gap as a verdict: "you were out by €92 — that's €1,105
 * a year you can’t account for", said to somebody whose €92 was their own
 * transfer, on the screen the whole flow is built towards. The guess still gets
 * its answer. The answer stops claiming to be something the rows cannot say
 * yet, and the sentence under it hands over to the step that can.
 */
function WhatLeft({
    guess,
    money,
}: {
    guess?: number;
    money: (amount: number) => string;
}) {
    if (guess === undefined) {
        return __(
            'Everything that left the account — money you moved to your own accounts included.',
        );
    }

    return (
        <StepFilled
            sentence={__(
                'You guessed :guess. This is everything that left the account — money you moved to your own accounts included.',
            )}
            values={{
                guess: (
                    <span className="text-muted-foreground">
                        {money(guess)}
                    </span>
                ),
            }}
        />
    );
}

/**
 * What the figure underneath is a figure of. The running month says so outright:
 * naming it the way a finished month is named is what made the number read as a
 * whole month's spending in the first place.
 */
function sectionLabel(data: SpendingReveal, locale: string): string {
    if (data.is_partial) {
        return __('This month so far');
    }

    return data.is_last_month
        ? __('Last month')
        : monthName(data.month, locale);
}

/** The revealed month, named, for an import that is not last month's. */
function monthName(month: string, locale: string, withYear = true): string {
    const [year, index] = month.split('-').map(Number);

    return new Intl.DateTimeFormat(locale, {
        month: 'long',
        ...(withYear ? { year: 'numeric' } : {}),
    }).format(new Date(year, index - 1, 1));
}

/**
 * The spending variant for a month made only of the round figures the user
 * typed in. It is their own answer read back, so the screen says so: the
 * figure is their basics, and what sits between it and the guess is the small
 * spending nobody types in — not a verdict on the guess.
 */
function RevealEstimate({
    data,
    spendingGuess,
    onContinue,
}: {
    data: SpendingReveal;
    spendingGuess?: number;
    onContinue: () => void;
}) {
    const locale = useLocale();
    const money = (amount: number) =>
        formatCurrency(amount, data.currency_code, locale, 0, 0);
    const gap =
        spendingGuess !== undefined && data.spent < spendingGuess
            ? spendingGuess - data.spent
            : null;

    return (
        <StepScreen
            footer={<StepButton text={__('Continue')} onClick={onContinue} />}
        >
            <div className="flex flex-col gap-6">
                <div className="flex flex-col gap-2.5">
                    <span className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                        {__(':month · your estimate', {
                            month: monthName(data.month, locale, false),
                        })}
                    </span>
                    <h1 className="text-3xl leading-[1.14] font-semibold tracking-tight text-balance md:text-[2rem]">
                        {__('Your basics come to :amount a month', {
                            amount: money(data.spent),
                        })}
                    </h1>
                    <p className="text-[15px] leading-relaxed text-pretty text-muted-foreground">
                        {gap !== null && spendingGuess !== undefined ? (
                            <StepEmphasis
                                sentence={__(
                                    'You guessed :guess. The other :gap is the part nobody remembers — coffees, Amazon, the odd dinner. It shows up once your real movements are in.',
                                    { guess: money(spendingGuess) },
                                )}
                                word={money(gap)}
                            />
                        ) : (
                            __(
                                'That’s what you typed for last month. Your real movements will fill in the rest.',
                            )
                        )}
                    </p>
                </div>

                {gap !== null && (
                    <BasicsBar spent={data.spent} gap={gap} money={money} />
                )}

                {(data.estimates ?? []).length > 0 && (
                    <StepList>
                        {(data.estimates ?? []).map((estimate) => (
                            <div
                                key={estimate.name}
                                className="flex items-center justify-between gap-3.5 py-3"
                            >
                                <span className="truncate text-[15px] font-medium">
                                    {estimate.name}
                                </span>
                                <span className="text-[15px] font-semibold tabular-nums">
                                    {money(estimate.amount)}
                                </span>
                            </div>
                        ))}
                    </StepList>
                )}

                <StepCallout>
                    {__(
                        'Saved as estimates. Bring last month in from your bank whenever you like and we swap them for the real thing.',
                    )}
                </StepCallout>
            </div>
        </StepScreen>
    );
}

/**
 * The basics against the guess, as one bar: the part the user typed in solid,
 * the part nothing accounts for yet hatched.
 */
function BasicsBar({
    spent,
    gap,
    money,
}: {
    spent: number;
    gap: number;
    money: (amount: number) => string;
}) {
    const share = (spent / (spent + gap)) * 100;

    return (
        <figure className="flex flex-col gap-2.5">
            <div
                role="img"
                aria-label={__(
                    'Basics :amount of a :guess guess; :gap not found yet',
                    {
                        amount: money(spent),
                        guess: money(spent + gap),
                        gap: money(gap),
                    },
                )}
                className="flex h-10 overflow-hidden rounded-lg border"
            >
                <div className="bg-foreground" style={{ width: `${share}%` }} />
                <div
                    className="flex-1 bg-muted"
                    style={{
                        backgroundImage:
                            'repeating-linear-gradient(135deg, transparent 0 6px, var(--border) 6px 8px)',
                    }}
                />
            </div>
            <figcaption className="flex justify-between gap-3 text-[13px] tabular-nums">
                <span className="flex items-center gap-1.5">
                    <span className="size-2.5 rounded-xs bg-foreground" />
                    {__('Basics :amount', { amount: money(spent) })}
                </span>
                <span className="flex items-center gap-1.5 text-muted-foreground">
                    <span className="size-2.5 rounded-xs border border-muted-foreground/50 bg-muted" />
                    {__('Not found yet :gap', { gap: money(gap) })}
                </span>
            </figcaption>
        </figure>
    );
}

/**
 * The variant for someone with no month to read back.
 *
 * Two different readers arrive here. One brought a pension, a mortgage or a
 * broker and no current account: they are missing half the picture, and the
 * screen offers it. The other brought a current account whose movements we
 * could not make a month out of — too few, or none of them outgoing. Telling
 * that one their accounts "move as single numbers" and offering to add the
 * current account they just connected is the screen answering somebody else's
 * situation, so it says what is actually true of theirs and gets out of the way.
 */
function RevealAssets({
    data,
    spendingGuess,
    onContinue,
    onAddAccount,
    onAddExpenses,
}: {
    data: AssetsReveal;
    spendingGuess?: number;
    onContinue: () => void;
    onAddAccount: () => void;
    /** Type last month in, for the reader with a hand-kept account to put it in. */
    onAddExpenses: () => void;
}) {
    const locale = useLocale();
    const awaitingMovements = data.has_spending_accounts;
    // The second chance: a month typed in by hand lands the spending reveal
    // this reader came for. Only where there is an account to type it into.
    const offerAccount = awaitingMovements ? data.quick_expenses_account : null;

    return (
        <StepScreen
            title={__('You’re worth :amount', {
                amount: formatCurrency(
                    data.net_worth,
                    data.currency_code,
                    locale,
                    0,
                    0,
                ),
            })}
            description={
                offerAccount
                    ? __(
                          'That’s the balance side. The spending side — where last month actually went — needs a month of movements.',
                      )
                    : awaitingMovements
                      ? __(
                            'Not enough has left these accounts yet to read a month back to you. That number is still one most people have never seen in one place.',
                        )
                      : __(
                            'No movements to read here — these accounts move as single numbers. That number is still one most people have never seen in one place.',
                        )
            }
            footer={
                offerAccount ? (
                    <>
                        <StepButton
                            text={__('Add last month in round figures')}
                            onClick={onAddExpenses}
                        />
                        <StepButton
                            text={__('Continue without it')}
                            variant="ghost"
                            onClick={onContinue}
                        />
                    </>
                ) : awaitingMovements ? (
                    <StepButton text={__('Continue')} onClick={onContinue} />
                ) : (
                    <>
                        <StepButton
                            text={__('Add a current account')}
                            onClick={onAddAccount}
                        />
                        <StepButton
                            text={__('Continue without one')}
                            variant="ghost"
                            onClick={onContinue}
                        />
                    </>
                )
            }
        >
            <div className="flex flex-col gap-6">
                <StepList>
                    {data.accounts.map((account) => (
                        <StepRow
                            key={account.id}
                            title={account.name}
                            description={
                                account.connected
                                    ? __('Connected')
                                    : __('Added by hand')
                            }
                            trailing={
                                <span className="text-[15px] font-semibold tabular-nums">
                                    {formatCurrency(
                                        account.balance,
                                        data.currency_code,
                                        locale,
                                        0,
                                        0,
                                    )}
                                </span>
                            }
                        />
                    ))}
                </StepList>

                {offerAccount && spendingGuess !== undefined ? (
                    <div className="flex flex-col gap-3 rounded-lg border p-4.5">
                        <span className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                            {__('You guessed')}
                        </span>
                        <span className="text-[28px] leading-none font-semibold tracking-tight tabular-nums">
                            <StepFilled
                                sentence={__(':guess a month')}
                                values={{
                                    guess: formatCurrency(
                                        spendingGuess,
                                        data.currency_code,
                                        locale,
                                        0,
                                        0,
                                    ),
                                }}
                            />
                        </span>
                        <span className="text-sm leading-normal text-pretty text-muted-foreground">
                            {__(
                                'Two minutes of round figures for :account and we can tell you how close you were.',
                                { account: offerAccount.name },
                            )}
                        </span>
                    </div>
                ) : (
                    <StepCallout>
                        {awaitingMovements
                            ? __(
                                  'Once a month of movements is in, this page changes shape: you get the spending side too, and we can tell you whether that number is going up or down each month.',
                              )
                            : __(
                                  'Connect a current account and this page changes shape: you get the spending side too, and we can tell you whether that number is going up or down each month.',
                              )}
                    </StepCallout>
                )}
            </div>
        </StepScreen>
    );
}
