import {
    AiSuggestion,
    AiSuggestionCard,
    SuggestionDraft,
} from '@/components/onboarding/ai-suggestion-card';
import { StepButton } from '@/components/onboarding/step-button';
import { StepList } from '@/components/onboarding/step-list';
import { StepNote, StepScreen } from '@/components/onboarding/step-screen';
import { Skeleton } from '@/components/ui/skeleton';
import { useCheapestMonthlyPrice } from '@/hooks/use-cheapest-monthly-price';
import { isRecoveringFromExpiredSession } from '@/lib/session-expiry-recovery';
import { store as storeConsent } from '@/routes/ai/consent';
import { accept, generate, show } from '@/routes/ai/rule-suggestions';
import { type SharedData } from '@/types';
import { type Category } from '@/types/category';
import { type SignupPlan } from '@/types/pricing';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Loader2, Sparkles } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

// Client-side give-up: the backend marks the run failed on timeout/crash, but
// this guarantees the spinner resolves even if the worker dies before it can.
// A little beyond the "up to two minutes" the UI promises.
const MAX_POLL_MS = 3 * 60_000;

interface SuggestionState {
    available: boolean;
    consented: boolean;
    requires_upgrade: boolean;
    eligible: boolean;
    transaction_count: number;
    min_transactions: number;
    auto_select_confidence: number;
    throttled: boolean;
    throttled_until: string | null;
    run: { id: string; status: string; suggestions_count: number } | null;
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
    onComplete: () => void;
}

export function StepAiSuggestions({
    categories,
    hasConnectedAccount,
    signupPlan = null,
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
                onCompleteRef.current();
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
        startGenerate();
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
            onCompleteRef.current();
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
                title={__('Rules created')}
                description={__(
                    'We created :rules rules and categorized :count transactions for you.',
                    {
                        rules: summary.rules_created,
                        count: summary.transactions_categorized,
                    },
                )}
                footer={
                    <StepButton text={__('Continue')} onClick={onComplete} />
                }
            />
        );
    }

    if (!timedOut && (!state || busy || isRunning(state))) {
        return (
            <StepScreen
                width="xl"
                title={__('Looking for patterns')}
                description={__(
                    'We’re finding the rules that will categorize most of your transactions automatically.',
                )}
            >
                <div className="flex flex-col gap-5">
                    <GeneratingMessages />

                    <StepList>
                        <SuggestionCardSkeleton />
                        <SuggestionCardSkeleton />
                        <SuggestionCardSkeleton />
                    </StepList>

                    <p className="text-[13px] text-muted-foreground">
                        {__('This can take up to two minutes.')}
                    </p>
                </div>
            </StepScreen>
        );
    }

    // ponytail: unreachable — timedOut only flips after state has loaded; the
    // guard just restores non-null narrowing for the branches below.
    if (!state) {
        return null;
    }

    if (!state.consented) {
        return (
            <StepScreen
                title={__('Let AI organize your money')}
                description={__(
                    'With your permission, we’ll send merchant names from your transactions to our AI provider to suggest categorization rules. We never send your full financial picture, and you review every rule before it’s created.',
                )}
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
                            text={__('Suggest my rules with AI')}
                            icon={Sparkles}
                            onClick={acceptConsent}
                            loading={busy}
                        />
                        <StepButton
                            text={__('No thanks')}
                            variant="ghost"
                            onClick={onComplete}
                        />
                    </>
                }
            />
        );
    }

    if (!state.eligible) {
        return (
            <StepScreen
                align="center"
                title={__('AI suggestions need more data')}
                description={__(
                    'Once you have at least :count transactions, you can generate rule suggestions from Settings → Automation rules.',
                    { count: state.min_transactions },
                )}
                footer={
                    <StepButton text={__('Continue')} onClick={onComplete} />
                }
            />
        );
    }

    if (timedOut || state.run?.status === 'failed') {
        return (
            <StepScreen
                align="center"
                title={__('We couldn’t generate suggestions')}
                description={__(
                    'Something went wrong. You can try again or skip for now.',
                )}
                footer={
                    <>
                        <StepButton
                            text={__('Try again')}
                            onClick={startGenerate}
                        />
                        <StepButton
                            text={__('Skip for now')}
                            variant="ghost"
                            onClick={onComplete}
                        />
                    </>
                }
            />
        );
    }

    if (state.run?.status === 'empty' || state.suggestions.length === 0) {
        return (
            <StepScreen
                align="center"
                title={__('No clear patterns yet')}
                description={__(
                    'We couldn’t find confident rules to suggest right now. You can categorize your transactions in the next step.',
                )}
                footer={
                    <StepButton text={__('Continue')} onClick={onComplete} />
                }
            />
        );
    }

    const selectedCount = state.suggestions.filter(
        (s) => drafts[s.id]?.include,
    ).length;

    return (
        <StepScreen
            width="xl"
            title={__('Review your suggested rules')}
            description={__(
                'We found these patterns. Tweak anything you like, then create the rules — we’ll apply them to your transactions right away.',
            )}
            footer={
                <>
                    <StepButton
                        text={
                            selectedCount > 0
                                ? __('Create :count rules & apply', {
                                      count: selectedCount,
                                  })
                                : __('Continue')
                        }
                        onClick={submit}
                        loading={submitting}
                        loadingText={__('Applying…')}
                    />
                    <StepButton
                        text={__('Skip for now')}
                        variant="ghost"
                        onClick={onComplete}
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

const GENERATING_MESSAGE_INTERVAL_MS = 3500;

/**
 * Cycles through reassuring status messages while a run is in flight. The
 * backend exposes no real progress, so the messages step forward on a timer and
 * hold on the last one rather than looping back to the start (which would read
 * as the process restarting).
 */
function GeneratingMessages() {
    const messages = [
        __('Analysing your transactions…'),
        __('Finding related groups…'),
        __('Finding the right categories…'),
        __('Grouping everything together…'),
        __('Polishing your suggestions…'),
    ];
    const [index, setIndex] = useState(0);

    useEffect(() => {
        const id = setInterval(() => {
            setIndex((current) => Math.min(current + 1, messages.length - 1));
        }, GENERATING_MESSAGE_INTERVAL_MS);
        return () => clearInterval(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <div className="flex items-center gap-2.5 text-[15px] font-medium">
            <Loader2 className="size-4 shrink-0 animate-spin" />
            <span
                key={index}
                className="animate-in duration-300 fade-in"
                aria-live="polite"
            >
                {messages[index]}
            </span>
        </div>
    );
}

/**
 * Mirrors the collapsed {@link AiSuggestionCard} layout so the loading state
 * resembles the final UI: checkbox, summary line, match count, expand chevron.
 */
function SuggestionCardSkeleton() {
    return (
        <div className="flex items-center gap-3.5 py-4">
            <Skeleton className="size-5 shrink-0 rounded-md" />
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <Skeleton className="h-4 w-32 max-w-[45%]" />
                <Skeleton className="h-4 w-20 max-w-[30%]" />
            </div>
            <Skeleton className="h-3 w-14 shrink-0" />
            <Skeleton className="size-4 shrink-0 rounded" />
        </div>
    );
}
