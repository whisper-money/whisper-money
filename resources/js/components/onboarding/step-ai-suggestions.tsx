import {
    AiSuggestion,
    AiSuggestionCard,
    SuggestionDraft,
} from '@/components/onboarding/ai-suggestion-card';
import { StepButton } from '@/components/onboarding/step-button';
import { StepHeader } from '@/components/onboarding/step-header';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { store as storeConsent } from '@/routes/ai/consent';
import { accept, generate, show } from '@/routes/ai/rule-suggestions';
import { type SharedData } from '@/types';
import { type Category } from '@/types/category';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Loader2, PartyPopper, Sparkles, Wand2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

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
    onComplete: () => void;
}

export function StepAiSuggestions({
    categories,
    hasConnectedAccount,
    onComplete,
}: StepAiSuggestionsProps) {
    const [state, setState] = useState<SuggestionState | null>(null);
    const [drafts, setDrafts] = useState<Record<string, SuggestionDraft>>({});
    const [busy, setBusy] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [summary, setSummary] = useState<AcceptResponse['summary'] | null>(
        null,
    );
    const pollRef = useRef<ReturnType<typeof setTimeout>>(undefined);
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
        const { data } = await axios.get<SuggestionState>(show().url);
        applyState(data);
        if (isRunning(data)) {
            pollRef.current = setTimeout(poll, 3000);
        }
    }, [applyState]);

    const startGenerate = useCallback(async () => {
        setBusy(true);
        try {
            const { data } = await axios.post<SuggestionState>(generate().url);
            applyState(data);
            if (isRunning(data)) {
                poll();
            }
        } catch (error) {
            if (axios.isAxiosError(error) && error.response?.status === 422) {
                applyState(error.response.data as SuggestionState);
            }
        } finally {
            setBusy(false);
        }
    }, [applyState, poll]);

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
                    poll();
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
            setSubmitting(false);
        }
    };

    // --- Render states -----------------------------------------------------

    if (summary) {
        return (
            <Centered>
                <StepHeader
                    icon={PartyPopper}
                    iconContainerClassName="bg-gradient-to-br from-emerald-400 to-green-500"
                    title={__('Rules created')}
                    description={__(
                        'We created :rules rules and categorized :count transactions for you.',
                        {
                            rules: summary.rules_created,
                            count: summary.transactions_categorized,
                        },
                    )}
                />
                <StepButton text={__('Continue')} onClick={onComplete} />
            </Centered>
        );
    }

    if (!state || busy || isRunning(state)) {
        return (
            <Centered>
                <StepHeader
                    icon={Wand2}
                    iconContainerClassName="bg-gradient-to-br from-violet-500 to-purple-600"
                    title={__('Looking for patterns')}
                    description={__(
                        'We’re finding the rules that will categorize most of your transactions automatically.',
                    )}
                />
                <GeneratingMessages />
                <div className="w-full max-w-2xl space-y-3">
                    <SuggestionCardSkeleton />
                    <SuggestionCardSkeleton />
                    <SuggestionCardSkeleton />
                </div>
                <p className="text-xs text-muted-foreground">
                    {__('This can take up to two minutes.')}
                </p>
            </Centered>
        );
    }

    if (!state.consented) {
        return (
            <Centered>
                <StepHeader
                    icon={Sparkles}
                    iconContainerClassName="bg-gradient-to-br from-violet-500 to-purple-600"
                    title={__('Let AI organize your money')}
                    description={__(
                        'With your permission, we’ll send merchant names from your transactions to our AI provider to suggest categorization rules. We never send your full financial picture, and you review every rule before it’s created.',
                    )}
                />
                {state.requires_upgrade && <UpgradeNotice />}
                <div className="flex flex-col items-center gap-3">
                    <StepButton
                        text={__('Suggest my rules with AI')}
                        onClick={acceptConsent}
                        loading={busy}
                    />
                    <Button variant="ghost" onClick={onComplete}>
                        {__('No thanks')}
                    </Button>
                </div>
            </Centered>
        );
    }

    if (!state.eligible) {
        return (
            <Centered>
                <StepHeader
                    icon={Sparkles}
                    iconContainerClassName="bg-gradient-to-br from-violet-500 to-purple-600"
                    title={__('AI suggestions need more data')}
                    description={__(
                        'Once you have at least :count transactions, you can generate rule suggestions from Settings → Automation rules.',
                        { count: state.min_transactions },
                    )}
                />
                <StepButton text={__('Continue')} onClick={onComplete} />
            </Centered>
        );
    }

    if (state.run?.status === 'failed') {
        return (
            <Centered>
                <StepHeader
                    icon={Wand2}
                    iconContainerClassName="bg-gradient-to-br from-violet-500 to-purple-600"
                    title={__('We couldn’t generate suggestions')}
                    description={__(
                        'Something went wrong. You can try again or skip for now.',
                    )}
                />
                <div className="flex flex-col items-center gap-3">
                    <StepButton
                        text={__('Try again')}
                        onClick={startGenerate}
                    />
                    <Button variant="ghost" onClick={onComplete}>
                        {__('Skip for now')}
                    </Button>
                </div>
            </Centered>
        );
    }

    if (state.run?.status === 'empty' || state.suggestions.length === 0) {
        return (
            <Centered>
                <StepHeader
                    icon={Sparkles}
                    iconContainerClassName="bg-gradient-to-br from-violet-500 to-purple-600"
                    title={__('No clear patterns yet')}
                    description={__(
                        'We couldn’t find confident rules to suggest right now. You can categorize your transactions in the next step.',
                    )}
                />
                <StepButton text={__('Continue')} onClick={onComplete} />
            </Centered>
        );
    }

    const selectedCount = state.suggestions.filter(
        (s) => drafts[s.id]?.include,
    ).length;

    return (
        <div className="flex animate-in flex-col items-center pb-4 duration-500 fade-in slide-in-from-bottom-4">
            <StepHeader
                icon={Wand2}
                iconContainerClassName="bg-gradient-to-br from-violet-500 to-purple-600"
                title={__('Review your suggested rules')}
                description={__(
                    'We found these patterns. Tweak anything you like, then create the rules — we’ll apply them to your transactions right away.',
                )}
            />

            <div className="mb-5 w-full max-w-2xl space-y-3">
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
            </div>

            <div className="flex flex-col items-center gap-3">
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
                <Button variant="ghost" onClick={onComplete}>
                    {__('Skip for now')}
                </Button>
            </div>
        </div>
    );
}

/**
 * Warns free users (who haven't linked a bank yet) that turning on AI
 * suggestions commits them to picking a paid plan at the end of onboarding,
 * mirroring the notice shown when choosing a connected account.
 */
function UpgradeNotice() {
    const { pricing, locale } = usePage<SharedData>().props;

    const cheapestMonthlyPrice = useMemo(() => {
        const plans = Object.values(pricing.plans);
        if (plans.length === 0) {
            return null;
        }
        return Math.min(
            ...plans.map((plan) =>
                plan.billing_period === 'year' ? plan.price / 12 : plan.price,
            ),
        );
    }, [pricing.plans]);

    return (
        <div className="w-full max-w-md rounded-lg border border-emerald-100 bg-emerald-50 p-3 dark:border-emerald-900/50 dark:bg-emerald-900/20">
            <p className="text-center text-sm text-balance text-emerald-700 dark:text-emerald-300">
                {__(
                    "AI suggestions are a paid feature. Enable them and you'll choose a plan at the end of the onboarding.",
                )}
            </p>
            {cheapestMonthlyPrice !== null && (
                <p className="mt-1 text-center text-xs font-medium text-emerald-600 dark:text-emerald-400">
                    {__('From')}{' '}
                    {formatCurrency(
                        cheapestMonthlyPrice * 100,
                        pricing.currency,
                        locale,
                    )}
                    {__('/month')}
                </p>
            )}
        </div>
    );
}

function Centered({ children }: { children: React.ReactNode }) {
    return (
        <div className="flex animate-in flex-col items-center gap-6 pb-4 duration-500 fade-in slide-in-from-bottom-4">
            {children}
        </div>
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
        <div className="flex items-center gap-2 text-sm font-medium text-foreground">
            <Loader2 className="size-4 shrink-0 animate-spin text-violet-500" />
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
        <div className="flex items-center gap-3 rounded-xl border bg-card p-3">
            <Skeleton className="size-4 shrink-0 rounded" />
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <Skeleton className="h-4 w-32 max-w-[45%]" />
                <Skeleton className="h-4 w-20 max-w-[30%]" />
            </div>
            <Skeleton className="h-3 w-14 shrink-0" />
            <Skeleton className="size-4 shrink-0 rounded" />
        </div>
    );
}
