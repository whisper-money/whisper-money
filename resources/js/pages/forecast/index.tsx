import { index as accountsList } from '@/actions/App/Http/Controllers/AccountController';
import { index as forecastIndex } from '@/actions/App/Http/Controllers/ForecastController';
import { ForecastAccountTabs } from '@/components/forecast/forecast-account-tabs';
import { ForecastAlerts } from '@/components/forecast/forecast-alerts';
import { ForecastChart } from '@/components/forecast/forecast-chart';
import {
    ForecastEditorProvider,
    useForecastEditor,
} from '@/components/forecast/forecast-editor';
import { ForecastHorizonPicker } from '@/components/forecast/forecast-horizon-picker';
import { ForecastKpis } from '@/components/forecast/forecast-kpis';
import { ForecastPlanTable } from '@/components/forecast/forecast-plan-table';
import {
    ForecastReviewDialog,
    type ForecastReviewStep,
} from '@/components/forecast/forecast-review-dialog';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { initiallyExpandedGroups } from '@/lib/forecast/plan-rows';
import { type BreadcrumbItem } from '@/types';
import {
    type ForecastData,
    type ForecastPageProps,
    type ForecastReviewPrompt,
} from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { Head, Link, usePage } from '@inertiajs/react';
import { type ReactNode, useEffect, useRef, useState } from 'react';

/** The month picked by default: the one with the problem, else the next one. */
function defaultMonth(forecast: ForecastData): string | null {
    if (forecast.alert.level === 'strong' && forecast.alert.month) {
        return forecast.alert.month;
    }

    return (
        forecast.months.find((column) => column.status === 'future')?.month ??
        forecast.months[0]?.month ??
        null
    );
}

/** The header's "Add to the forecast", which opens the add sheet once there is an account. */
function AddButton() {
    const editor = useForecastEditor();

    return (
        <Button
            disabled={editor === null}
            onClick={() => editor?.open({ kind: 'add' })}
            data-test="forecast-add"
        >
            {__('Add to the forecast')}
        </Button>
    );
}

/**
 * Where skipping the review is remembered, per user, so the review that opens
 * on its own the first time does not open again on every visit.
 */
function skippedKey(userId: string | undefined): string {
    return `forecast-review-skipped:${userId ?? 'guest'}`;
}

function reviewWasSkipped(userId: string | undefined): boolean {
    try {
        return window.localStorage.getItem(skippedKey(userId)) === '1';
    } catch {
        return false;
    }
}

function rememberReviewSkipped(userId: string | undefined): void {
    try {
        window.localStorage.setItem(skippedKey(userId), '1');
    } catch {
        // Private mode: the review may open again next time, which is fine.
    }
}

/**
 * The page's review: it opens on its own the first time the user comes with
 * no line at all, and on demand from the plan's link, the empty state or the
 * "yearly payments" shortcut. Closing it without saving, while there is still
 * no line, counts as skipping it.
 */
function useReview(prompt: ForecastReviewPrompt, canReview: boolean) {
    const userId = usePage<{ auth?: { user?: { id?: string } } }>().props.auth
        ?.user?.id;
    const [open, setOpen] = useState(false);
    const [step, setStep] = useState<ForecastReviewStep>(1);
    const checkedArrival = useRef(false);

    useEffect(() => {
        if (checkedArrival.current) {
            return;
        }

        checkedArrival.current = true;

        if (canReview && !prompt.hasLines && !reviewWasSkipped(userId)) {
            setOpen(true);
        }
    }, [canReview, prompt.hasLines, userId]);

    return {
        open,
        step,
        openAt: (at: ForecastReviewStep) => {
            setStep(at);
            setOpen(true);
        },
        onOpenChange: (next: boolean) => {
            if (!next && !prompt.hasLines) {
                rememberReviewSkipped(userId);
            }

            setOpen(next);
        },
        onSaved: () => setOpen(false),
    };
}

/** The page of a user with no line yet: everything is estimated. */
function NoLinesYet({
    newCount,
    onReview,
}: {
    newCount: number | undefined;
    onReview: () => void;
}) {
    return (
        <Card data-test="forecast-no-lines">
            <CardContent>
                <EmptyState size="inline">
                    <EmptyStateTitle>
                        {__('Your forecast is only an estimate for now')}
                    </EmptyStateTitle>
                    <EmptyStateDescription>
                        {__(
                            'Confirm what repeats in your movements (salary, rent, bills) and add your yearly payments, and the forecast will rest on what you know instead of on averages.',
                        )}
                    </EmptyStateDescription>
                    <Button
                        onClick={onReview}
                        data-test="forecast-start-review"
                    >
                        {newCount === undefined || newCount > 0
                            ? __('Review what we found (:count)', {
                                  count: newCount ?? '…',
                              })
                            : __('Add your yearly payments')}
                    </Button>
                </EmptyState>
            </CardContent>
        </Card>
    );
}

function ForecastContent({
    forecast,
    reviewPrompt,
    newSuggestionCount,
    onOpenReview,
}: {
    forecast: ForecastData;
    reviewPrompt: ForecastReviewPrompt;
    newSuggestionCount: number | undefined;
    onOpenReview: (step: ForecastReviewStep) => void;
}) {
    const [selectedMonth, setSelectedMonth] = useState(() =>
        defaultMonth(forecast),
    );
    const planRef = useRef<HTMLDivElement>(null);

    return (
        <>
            {!reviewPrompt.hasLines && (
                <NoLinesYet
                    newCount={newSuggestionCount}
                    onReview={() =>
                        onOpenReview(newSuggestionCount === 0 ? 2 : 1)
                    }
                />
            )}
            <ForecastAccountTabs
                accounts={forecast.accounts}
                selectedId={forecast.account.id}
                horizon={forecast.horizon}
            />
            <ForecastAlerts
                forecast={forecast}
                onShowMonth={(month) => {
                    setSelectedMonth(month);
                    planRef.current?.scrollIntoView({ behavior: 'smooth' });
                }}
            />
            <ForecastKpis
                forecast={forecast}
                onAddYearly={() => onOpenReview(2)}
            />
            <ForecastChart
                forecast={forecast}
                selectedMonth={selectedMonth}
                onSelectMonth={setSelectedMonth}
            />
            <div ref={planRef}>
                <ForecastPlanTable
                    key={forecast.account.id}
                    forecast={forecast}
                    selectedMonth={selectedMonth}
                    initiallyExpanded={initiallyExpandedGroups(
                        forecast.plan,
                        forecast.alert.month,
                    )}
                    headerAction={
                        newSuggestionCount && reviewPrompt.hasLines ? (
                            <button
                                type="button"
                                className="text-[13px] underline underline-offset-4 hover:text-foreground/80"
                                onClick={() => onOpenReview(1)}
                                data-test="forecast-review-link"
                            >
                                {newSuggestionCount === 1
                                    ? __('Recurring detected (1 new)')
                                    : __('Recurring detected (:count new)', {
                                          count: newSuggestionCount,
                                      })}
                            </button>
                        ) : null
                    }
                />
            </div>
            <p className="text-xs text-muted-foreground">
                {__(
                    'How it is worked out: today’s balance + what is concrete (confirmed recurring, yearly and one-off lines) + an estimated rest per category (the median of 3 months without the concrete lines, or your budget when it fits the category). The likely range applies to the estimate only: for the most active users, a real month lands within ±40% of the estimate.',
                )}
            </p>
        </>
    );
}

/** The edit sheets, once there is an account to edit. */
function EditorBoundary({
    forecast,
    projectionInput,
    children,
}: ForecastPageProps & { children: ReactNode }) {
    if (forecast.account === null) {
        return children;
    }

    return (
        <ForecastEditorProvider
            forecast={forecast}
            projectionInput={projectionInput}
        >
            {children}
        </ForecastEditorProvider>
    );
}

/**
 * The cash-flow forecast of one account: how the user is going to be, from
 * what they know and what they usually spend.
 */
export default function ForecastPage({
    forecast,
    projectionInput,
    reviewPrompt,
    newSuggestionCount,
    review,
}: ForecastPageProps) {
    const reviewState = useReview(reviewPrompt, forecast.account !== null);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: __('Forecast'), href: forecastIndex().url },
    ];

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Forecast')} />
            <EditorBoundary
                forecast={forecast}
                projectionInput={projectionInput}
                reviewPrompt={reviewPrompt}
            >
                <div className="mx-auto flex w-full max-w-page flex-col gap-5 p-4">
                    <PageHeader
                        title={__('Forecast')}
                        description={__(
                            'How you are going to be, from what you know and what you usually spend. The more you make concrete, the more reliable it is.',
                        )}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <ForecastHorizonPicker
                                    horizon={forecast.horizon}
                                    accountId={forecast.account?.id ?? null}
                                />
                                <AddButton />
                            </div>
                        }
                    />
                    {forecast.account === null ? (
                        <Card>
                            <CardContent>
                                <EmptyState>
                                    <EmptyStateTitle>
                                        {__('No accounts to forecast')}
                                    </EmptyStateTitle>
                                    <EmptyStateDescription>
                                        {__(
                                            'The forecast covers the accounts of this space that are not archived. Add one to see how its balance is going to evolve.',
                                        )}
                                    </EmptyStateDescription>
                                    <Button asChild variant="outline">
                                        <Link href={accountsList()}>
                                            {__('Go to accounts')}
                                        </Link>
                                    </Button>
                                </EmptyState>
                            </CardContent>
                        </Card>
                    ) : (
                        <ForecastContent
                            key={`${forecast.account.id}:${forecast.horizon}`}
                            forecast={forecast}
                            reviewPrompt={reviewPrompt}
                            newSuggestionCount={newSuggestionCount}
                            onOpenReview={reviewState.openAt}
                        />
                    )}
                    {forecast.account !== null && (
                        <ForecastReviewDialog
                            open={reviewState.open}
                            onOpenChange={reviewState.onOpenChange}
                            onSaved={reviewState.onSaved}
                            isPro={forecast.isPro}
                            review={review}
                            initialStep={reviewState.step}
                            defaultAccountId={forecast.account.id}
                        />
                    )}
                </div>
            </EditorBoundary>
        </AppSidebarLayout>
    );
}
