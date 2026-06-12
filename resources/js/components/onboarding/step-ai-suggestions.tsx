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
import { type Category } from '@/types/category';
import { __ } from '@/utils/i18n';
import axios from 'axios';
import { PartyPopper, Sparkles, Wand2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface SuggestionState {
    available: boolean;
    consented: boolean;
    eligible: boolean;
    transaction_count: number;
    min_transactions: number;
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
    onComplete: () => void;
}

export function StepAiSuggestions({
    categories,
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
                        include: true,
                        token: suggestion.match_token,
                        categoryId: suggestion.proposed_category?.id ?? null,
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

        const chosen = state.suggestions.filter((s) => drafts[s.id]?.include);

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
                    id: suggestion.id,
                    match_field: suggestion.match_field,
                    match_operator: suggestion.match_operator,
                    match_token: draft.token,
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
            setSummary(data.summary);
        } finally {
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
                <div className="w-full max-w-2xl space-y-3">
                    <Skeleton className="h-24 w-full rounded-xl" />
                    <Skeleton className="h-24 w-full rounded-xl" />
                    <Skeleton className="h-24 w-full rounded-xl" />
                </div>
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
                                include: true,
                                token: suggestion.match_token,
                                categoryId:
                                    suggestion.proposed_category?.id ?? null,
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

function Centered({ children }: { children: React.ReactNode }) {
    return (
        <div className="flex animate-in flex-col items-center gap-6 pb-4 duration-500 fade-in slide-in-from-bottom-4">
            {children}
        </div>
    );
}
