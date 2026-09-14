import {
    AiSuggestion,
    AiSuggestionCard,
    SuggestionDraft,
} from '@/components/onboarding/ai-suggestion-card';
import { StepButton } from '@/components/onboarding/step-button';
import {
    StepChevron,
    StepList,
    StepRow,
} from '@/components/onboarding/step-list';
import {
    StepCallout,
    StepEmphasis,
    StepNote,
    StepScreen,
} from '@/components/onboarding/step-screen';
import { Spinner } from '@/components/ui/spinner';
import { useCheapestMonthlyPrice } from '@/hooks/use-cheapest-monthly-price';
import { captureEvent } from '@/lib/posthog';
import { isRecoveringFromExpiredSession } from '@/lib/session-expiry-recovery';
import { store as storeConsent } from '@/routes/ai/consent';
import { accept, generate, show } from '@/routes/ai/rule-suggestions';
import { categorize } from '@/routes/onboarding';
import { type SharedData } from '@/types';
import { type Category } from '@/types/category';
import { type SignupPlan } from '@/types/pricing';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    ChartNoAxesColumnIncreasing,
    Check,
    RotateCcw,
    Sparkles,
    Undo2,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

// Client-side give-up: the backend marks the run failed on timeout/crash, but
// this guarantees the spinner resolves even if the worker dies before it can.
// A little beyond the couple of minutes the screen promises.
const MAX_POLL_MS = 3 * 60_000;

interface SuggestionState {
    available: boolean;
    consented: boolean;
    /** Whether we are asking for consent or asking for it again. */
    previously_consented: boolean;
    requires_upgrade: boolean;
    eligible: boolean;
    transaction_count: number;
    min_transactions: number;
    auto_select_confidence: number;
    throttled: boolean;
    throttled_until: string | null;
    run: {
        id: string;
        status: string;
        merchants_considered: number;
        suggestions_count: number;
    } | null;
    suggestions: AiSuggestion[];
}

interface AcceptResponse {
    summary: { rules_created: number; transactions_categorized: number };
    applied_to_existing: boolean;
}

interface StepAiSuggestionsProps {
    categories: Category[];
    hasConnectedAccount: boolean;
    signupPlan?: SignupPlan | null;
    /** Back to the accounts hub, for a user who arrived with too little to read. */
    onAddAccount: () => void;
    onComplete: () => void;
}

export function StepAiSuggestions({
    categories,
    hasConnectedAccount,
    signupPlan = null,
    onAddAccount,
    onComplete,
}: StepAiSuggestionsProps) {
    const [state, setState] = useState<SuggestionState | null>(null);
    const [drafts, setDrafts] = useState<Record<string, SuggestionDraft>>({});
    const [busy, setBusy] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [timedOut, setTimedOut] = useState(false);
    const [summary, setSummary] = useState<AcceptResponse['summary'] | null>(
        null,
    );
    const pollRef = useRef<ReturnType<typeof setTimeout>>(undefined);
    const deadlineRef = useRef(0);
    const onCompleteRef = useRef(onComplete);
    onCompleteRef.current = onComplete;

    // Every way out of this step goes through here, not just accepting rules:
    // fewer than the minimum transactions, an empty run, a failed or timed-out
    // one and a plain skip all land the user on the next step, and all of them
    // paid for the same thing. Leaving starts the AI pass over whatever the
    // generated rules left uncategorized, so it runs during the two or three
    // steps still ahead instead of on a dashboard the user is already reading.
    // Fire and forget: the server drops it without a plan or without consent,
    // and nothing here should wait on it.
    const leaveStep = useCallback(() => {
        axios.post(categorize().url).catch(() => {
            // The dashboard still prompts for whatever stays uncategorized.
        });
        onCompleteRef.current();
    }, []);

    const applyState = useCallback((data: SuggestionState) => {
        setState(data);
        setDrafts((prev) => {
            const next = { ...prev };
            for (const suggestion of data.suggestions) {
                if (!next[suggestion.id]) {
                    next[suggestion.id] = {
                        // Auto-select only confident suggestions; weaker ones
                        // are shown but left for the user to opt into.
                        include:
                            suggestion.confidence >=
                            data.auto_select_confidence,
                        categoryId: suggestion.proposed_category?.id ?? null,
                        values: suggestion.values.map((value) => ({
                            field: value.match_field,
                            operator: value.match_operator,
                            token: value.match_token,
                        })),
                    };
                }
            }
            return next;
        });
    }, []);

    const isRunning = (data: SuggestionState | null): boolean =>
        data?.run?.status === 'pending' || data?.run?.status === 'processing';

    const poll = useCallback(async () => {
        try {
            const { data } = await axios.get<SuggestionState>(show().url);
            applyState(data);
            if (!isRunning(data)) {
                return;
            }
        } catch {
            // Transient failure (network blip, expired session, 5xx). Keep
            // retrying until the deadline instead of silently dying here.
        }
        // Guarantee the client never spins forever: if the run hasn't reached a
        // terminal status in time (e.g. the worker was killed before it could
        // mark the run failed), surface the failed state so the user can retry
        // or skip.
        if (Date.now() >= deadlineRef.current) {
            setTimedOut(true);
            return;
        }
        pollRef.current = setTimeout(poll, 3000);
    }, [applyState]);

    const startPolling = useCallback(() => {
        setTimedOut(false);
        deadlineRef.current = Date.now() + MAX_POLL_MS;
        poll();
    }, [poll]);

    const startGenerate = useCallback(async () => {
        setBusy(true);
        setTimedOut(false);
        try {
            const { data } = await axios.post<SuggestionState>(generate().url);
            applyState(data);
            if (isRunning(data)) {
                startPolling();
            }
        } catch (error) {
            if (axios.isAxiosError(error) && error.response?.status === 422) {
                applyState(error.response.data as SuggestionState);
            }
        } finally {
            setBusy(false);
        }
    }, [applyState, startPolling]);

    useEffect(() => {
        let cancelled = false;

        (async () => {
            try {
                const { data } = await axios.get<SuggestionState>(show().url);
                if (cancelled) {
                    return;
                }
                applyState(data);

                if (isRunning(data)) {
                    startPolling();
                } else if (
                    data.consented &&
                    data.eligible &&
                    !data.throttled &&
                    !data.run
                ) {
                    startGenerate();
                } else if (!data.consented && hasConnectedAccount) {
                    // A linked bank already commits the user to a paid plan, so
                    // enabling AI adds no cost — skip the consent prompt and turn
                    // it on directly. setBusy batches with applyState above, so
                    // the consent screen never flashes.
                    setBusy(true);
                    await axios.post(storeConsent().url);
                    if (!cancelled) {
                        startGenerate();
                    }
                }
            } catch {
                // Never block onboarding if the AI step can't load.
                leaveStep();
            }
        })();

        return () => {
            cancelled = true;
            clearTimeout(pollRef.current);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const acceptConsent = async () => {
        setBusy(true);
        try {
            await axios.post(storeConsent().url);
        } finally {
            setBusy(false);
        }
        // Reported once the consent is stored, not when the button was pressed:
        // a request that failed left the user without AI either way.
        captureEvent('onboarding_ai_consent', { granted: true });
        startGenerate();
    };

    const declineConsent = () => {
        captureEvent('onboarding_ai_consent', { granted: false });
        leaveStep();
    };

    // The two "Skip for now" buttons leave the step with the work undone, and
    // they are worth telling apart: one is a user walking away from suggestions
    // that are on screen, the other is a user we failed to generate any for.
    const skipStep = (reason: 'suggestions_shown' | 'generation_failed') => {
        captureEvent('onboarding_step_skipped', {
            step: 'ai-suggestions',
            reason,
        });
        leaveStep();
    };

    const submit = async () => {
        if (!state) {
            return;
        }

        const chosen = state.suggestions.filter((s) => {
            const draft = drafts[s.id];
            return (
                draft?.include &&
                draft.values.some((value) => value.token.trim() !== '')
            );
        });

        if (chosen.length === 0) {
            leaveStep();
            return;
        }

        setSubmitting(true);
        try {
            const payload = chosen.map((suggestion) => {
                const draft = drafts[suggestion.id];
                const categoryId =
                    draft.categoryId && draft.categoryId !== 'uncategorized'
                        ? draft.categoryId
                        : null;

                return {
                    ids: suggestion.values.map((value) => value.id),
                    values: draft.values
                        .filter((value) => value.token.trim() !== '')
                        .map((value) => ({
                            match_field: value.field,
                            match_operator: value.operator,
                            match_token: value.token.trim(),
                        })),
                    proposed_category_id: categoryId,
                    new_category_name: categoryId
                        ? null
                        : suggestion.new_category_name,
                    new_category_direction: categoryId
                        ? null
                        : suggestion.new_category_direction,
                };
            });

            const { data } = await axios.post<AcceptResponse>(accept().url, {
                suggestions: payload,
            });

            // The rules were created via axios, so refresh the Inertia props the
            // later onboarding steps rely on (newly created rules + categories,
            // and the transactions that just got categorized) before advancing.
            router.reload({
                only: ['automationRules', 'categories', 'transactions'],
                onFinish: () => {
                    setSummary(data.summary);
                    setSubmitting(false);
                },
            });
        } catch {
            // Silence here read as a dead button. An expired session is already
            // being answered by a reload, and "try again in a moment" would be
            // the wrong thing to say on the way to the login screen - but a 5xx
            // or a lost connection leaves the user on this screen, and they need
            // to know the rules were not created.
            if (!isRecoveringFromExpiredSession()) {
                toast.error(
                    __(
                        'We could not create your rules. Try again in a moment.',
                    ),
                );
            }

            setSubmitting(false);
        }
    };

    // --- Render states -----------------------------------------------------

    if (summary) {
        return (
            <StepScreen
                align="center"
                title={__('Your rules are live')}
                description={__(
                    'We created :rules and filed :movements with them. From here they run on their own, on everything that arrives.',
                    {
                        rules:
                            summary.rules_created === 1
                                ? __('1 rule')
                                : __(':count rules', {
                                      count: summary.rules_created,
                                  }),
                        movements:
                            summary.transactions_categorized === 1
                                ? __('1 movement')
                                : __(':count movements', {
                                      count: summary.transactions_categorized,
                                  }),
                    },
                )}
                footer={
                    <StepButton text={__('Continue')} onClick={leaveStep} />
                }
            />
        );
    }

    if (!timedOut && (!state || busy || isRunning(state))) {
        return (
            <StepScreen
                align="center"
                title={__('Reading your merchants')}
                description={
                    state
                        ? __(
                              ':count movements, grouped by who you paid. This is the part that saves you an afternoon.',
                              { count: state.transaction_count },
                          )
                        : __(
                              'Your movements, grouped by who you paid. This is the part that saves you an afternoon.',
                          )
                }
            >
                <div className="flex flex-col gap-7">
                    <StepList>
                        <RunCounter
                            label={__('Merchants found')}
                            value={state?.run?.merchants_considered ?? 0}
                        />
                        <RunCounter
                            label={__('Rules drafted')}
                            value={state?.run?.suggestions_count ?? 0}
                        />
                    </StepList>

                    <p
                        className="flex items-center justify-center gap-2.5 text-center text-[13px] text-pretty text-muted-foreground"
                        aria-live="polite"
                    >
                        <Spinner className="size-3.5 shrink-0" />
                        {__(
                            'Up to a couple of minutes. You can leave this screen — it keeps going without you.',
                        )}
                    </p>
                </div>
            </StepScreen>
        );
    }

    // Unreachable in practice — timedOut only flips after state has loaded.
    // The guard is here to restore non-null narrowing for the branches below.
    if (!state) {
        return null;
    }

    if (!state.consented) {
        return (
            <StepScreen
                title={
                    state.previously_consented
                        ? __('Turn AI sorting back on?')
                        : __('Let AI draft your rules?')
                }
                description={
                    state.previously_consented
                        ? __(
                              'You switched this off, or we changed what we send and need you to look again. Here is exactly what it is.',
                          )
                        : __(
                              'We send one line at a time to our AI provider so it can draft your rules. Here is exactly what that means.',
                          )
                }
                footer={
                    <>
                        {/* Someone who signed up from a paid card has already
                            agreed to pay, so the upgrade warning would only be
                            noise. They still give consent explicitly: what gets
                            sent stays on screen. */}
                        {state.requires_upgrade && signupPlan !== 'paid' && (
                            <UpgradeNotice />
                        )}
                        <StepButton
                            text={__('Turn it on')}
                            icon={Sparkles}
                            onClick={acceptConsent}
                            loading={busy}
                        />
                        <StepButton
                            text={__('Leave it off')}
                            variant="ghost"
                            onClick={declineConsent}
                        />
                    </>
                }
            >
                <StepList>
                    <StepRow
                        icon={Check}
                        title={__('One line at a time, never the picture')}
                        description={__(
                            'The description and the amount — “MERCADONA 4412, €62.40”. Never your balance, your name or your account number.',
                        )}
                    />
                    <StepRow
                        icon={Check}
                        title={__('Every rule is yours to reject')}
                        description={__(
                            'Nothing is applied that you haven’t seen on the next screen.',
                        )}
                    />
                    <StepRow
                        icon={Check}
                        title={__('Turn it off whenever')}
                        description={__(
                            'Settings, one switch. Your categories stay.',
                        )}
                    />
                </StepList>

                <StepCallout>
                    <StepEmphasis
                        sentence={__(
                            'Without this you sort :count by hand. With it you approve a handful of rules and the rest happens on its own.',
                        )}
                        word={__(':count movements', {
                            count: state.transaction_count,
                        })}
                    />
                </StepCallout>
            </StepScreen>
        );
    }

    if (!state.eligible) {
        return (
            <StepScreen
                title={__('Not enough to learn from yet')}
                description={__(
                    'You have :count movements. Patterns start showing up around :min — below that we would be guessing, and a wrong rule is worse than no rule.',
                    {
                        count: state.transaction_count,
                        min: state.min_transactions,
                    },
                )}
                footer={
                    <>
                        <StepButton text={__('Continue')} onClick={leaveStep} />
                        <StepButton
                            text={__('Add more history first')}
                            variant="outline"
                            onClick={onAddAccount}
                        />
                    </>
                }
            >
                <StepList>
                    <StepRow
                        icon={ChartNoAxesColumnIncreasing}
                        title={__('Bring more history')}
                        description={__('Another file, or connect the bank')}
                        trailing={<StepChevron />}
                        onClick={onAddAccount}
                    />
                    <StepRow
                        icon={Check}
                        title={__('Or carry on')}
                        description={__(
                            'You file a handful by hand in the next step',
                        )}
                    />
                </StepList>

                <StepCallout>
                    {__(
                        'Nothing is lost by waiting. Any transaction can become a rule from the app itself, one at a time.',
                    )}
                </StepCallout>
            </StepScreen>
        );
    }

    if (timedOut || state.run?.status === 'failed') {
        return (
            <StepScreen
                title={__('That didn’t finish')}
                description={__(
                    'It ran too long and we stopped it. Your movements are safe and untouched — nothing half-categorised.',
                )}
                footer={
                    <>
                        <StepButton
                            text={__('Try again')}
                            onClick={startGenerate}
                            loading={busy}
                        />
                        <StepButton
                            text={__('Skip for now')}
                            variant="ghost"
                            onClick={() => skipStep('generation_failed')}
                        />
                    </>
                }
            >
                <StepList>
                    <StepRow
                        icon={RotateCcw}
                        title={__('Try again')}
                        description={__('Usually works on the second run')}
                    />
                    <StepRow
                        icon={Undo2}
                        title={__('Or sort them yourself')}
                        description={__(
                            ':count movements, at your own pace, whenever',
                            { count: state.transaction_count },
                        )}
                    />
                </StepList>

                <StepCallout>
                    {__(
                        'You are not stuck either way: any transaction can become a rule from the app itself, whenever you like.',
                    )}
                </StepCallout>
            </StepScreen>
        );
    }

    if (state.run?.status === 'empty' || state.suggestions.length === 0) {
        return (
            <StepScreen
                title={__('Nothing worth a rule')}
                description={__(
                    'We read all :count and found no merchant that repeats often enough to be worth automating. That is unusual, and it is not a problem.',
                    { count: state.transaction_count },
                )}
                footer={
                    <StepButton text={__('Continue')} onClick={leaveStep} />
                }
            >
                <StepList>
                    <StepRow
                        icon={Check}
                        title={__('Nothing was changed')}
                        description={__(
                            'Your movements are exactly as they were',
                        )}
                    />
                    <StepRow
                        icon={Sparkles}
                        title={__('You file a few by hand next')}
                        description={__(
                            'A handful of movements, and any of them can become a rule',
                        )}
                    />
                </StepList>

                <StepCallout>
                    {__(
                        'This usually means one-off purchases dominate your history — a house move, a trip, a year abroad. Next month will look different.',
                    )}
                </StepCallout>
            </StepScreen>
        );
    }

    const selectedCount = state.suggestions.filter(
        (s) => drafts[s.id]?.include,
    ).length;

    return (
        <StepScreen
            width="xl"
            title={
                state.suggestions.length === 1
                    ? __('1 rule, ready when you are')
                    : __(':count rules, ready when you are', {
                          count: state.suggestions.length,
                      })
            }
            description={__(
                'Each one files a merchant forever, backwards and forwards. Untick anything you’d rather decide case by case.',
            )}
            footer={
                <>
                    <StepButton
                        text={applyLabel(selectedCount)}
                        onClick={submit}
                        loading={submitting}
                        loadingText={__('Applying…')}
                    />
                    <StepNote>
                        {__(
                            'Any of them can be undone from the transaction itself.',
                        )}
                    </StepNote>
                    <StepButton
                        text={__('Skip for now')}
                        variant="ghost"
                        onClick={() => skipStep('suggestions_shown')}
                    />
                </>
            }
        >
            <StepList>
                {state.suggestions.map((suggestion) => (
                    <AiSuggestionCard
                        key={suggestion.id}
                        suggestion={suggestion}
                        draft={
                            drafts[suggestion.id] ?? {
                                include:
                                    suggestion.confidence >=
                                    state.auto_select_confidence,
                                categoryId:
                                    suggestion.proposed_category?.id ?? null,
                                values: suggestion.values.map((value) => ({
                                    field: value.match_field,
                                    operator: value.match_operator,
                                    token: value.match_token,
                                })),
                            }
                        }
                        categories={categories}
                        onChange={(draft) =>
                            setDrafts((prev) => ({
                                ...prev,
                                [suggestion.id]: draft,
                            }))
                        }
                    />
                ))}
            </StepList>
        </StepScreen>
    );
}

/** The action of the review screen, which is nothing at all when nothing is ticked. */
function applyLabel(selectedCount: number): string {
    if (selectedCount === 0) {
        return __('Continue');
    }

    return selectedCount === 1
        ? __('Apply 1 rule')
        : __('Apply :count rules', { count: selectedCount });
}

/**
 * Warns free users (who haven't linked a bank yet) that turning on AI
 * suggestions commits them to picking a paid plan at the end of onboarding,
 * mirroring the notice shown when choosing a connected account.
 */
function UpgradeNotice() {
    const { pricing, locale } = usePage<SharedData>().props;

    const cheapestMonthlyPrice = useCheapestMonthlyPrice();

    return (
        <>
            <StepNote emphasis>
                {__(
                    "AI suggestions are a paid feature. Enable them and you'll choose a plan at the end of the onboarding.",
                )}
            </StepNote>
            {cheapestMonthlyPrice !== null && (
                <StepNote>
                    {__('Standard plan, from :price/month.', {
                        price: formatCurrency(
                            cheapestMonthlyPrice * 100,
                            pricing.currency,
                            locale,
                        ),
                    })}
                </StepNote>
            )}
        </>
    );
}

/**
 * One of the two numbers the generating screen counts out loud. They are the
 * run's own figures rather than a timer dressed up as progress: merchants land
 * as soon as the grouping is done, rules as the model returns them.
 */
function RunCounter({ label, value }: { label: string; value: number }) {
    return (
        <div className="flex items-baseline justify-between gap-3 py-4">
            <span className="text-[15px] text-muted-foreground">{label}</span>
            <span className="text-2xl font-semibold tabular-nums">{value}</span>
        </div>
    );
}
