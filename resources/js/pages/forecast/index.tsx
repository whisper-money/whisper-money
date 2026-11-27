import { index as accountsList } from '@/actions/App/Http/Controllers/AccountController';
import { index as forecastIndex } from '@/actions/App/Http/Controllers/ForecastController';
import { ForecastAccountChoices } from '@/components/forecast/forecast-account-choices';
import { ForecastAccountTabs } from '@/components/forecast/forecast-account-tabs';
import { ForecastAlerts } from '@/components/forecast/forecast-alerts';
import { ForecastAllAlerts } from '@/components/forecast/forecast-all-alerts';
import { ForecastAllPlanTable } from '@/components/forecast/forecast-all-plan-table';
import { ForecastChart } from '@/components/forecast/forecast-chart';
import {
    ForecastEditorProvider,
    useForecastEditor,
} from '@/components/forecast/forecast-editor';
import { ForecastHorizonPicker } from '@/components/forecast/forecast-horizon-picker';
import {
    ForecastAllKpis,
    ForecastKpis,
} from '@/components/forecast/forecast-kpis';
import {
    ForecastMobileAddButton,
    ForecastMobileView,
} from '@/components/forecast/forecast-mobile';
import { ForecastPlanTable } from '@/components/forecast/forecast-plan-table';
import { ForecastRecurringCard } from '@/components/forecast/forecast-recurring-card';
import {
    ForecastReviewDialog,
    type ForecastReviewStep,
} from '@/components/forecast/forecast-review-dialog';
import { ForecastStartOverMenu } from '@/components/forecast/forecast-start-over';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import { useIsMobile } from '@/hooks/use-mobile';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { initiallyExpandedGroups } from '@/lib/forecast/plan-rows';
import { type BreadcrumbItem } from '@/types';
import {
    type ForecastAllData,
    type ForecastData,
    type ForecastEmpty,
    type ForecastPageProps,
    type ForecastReviewPrompt,
} from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { Head, Link, usePage } from '@inertiajs/react';
import { type ReactNode, useEffect, useRef, useState } from 'react';

/** The month picked by default: the one with the problem, else the next one. */
function defaultMonth(forecast: ForecastData | ForecastAllData): string | null {
    if (forecast.alert.level !== 'ok' && forecast.alert.month) {
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

function forgetReviewSkipped(userId: string | undefined): void {
    try {
        window.localStorage.removeItem(skippedKey(userId));
    } catch {
        // Private mode: nothing was remembered.
    }
}

/**
 * The page's review: it opens on its own the first time the user comes with
 * no line at all and has not answered it in this space, and on demand from
 * the plan's link, the empty state or the "yearly payments" shortcut.
 * Closing it without saving, while it is still unanswered, counts as
 * skipping it. Starting the forecast over forgets that it was skipped and
 * opens it again, as the first time.
 *
 * `prompt` is the page's prompt as the rest of the page reads it: a review
 * answered counts as having lines, since its first step may add none.
 */
function useReview(reviewPrompt: ForecastReviewPrompt, canReview: boolean) {
    const userId = usePage<{ auth?: { user?: { id?: string } } }>().props.auth
        ?.user?.id;
    const [open, setOpen] = useState(false);
    const [step, setStep] = useState<ForecastReviewStep>(1);
    const checkedArrival = useRef(false);
    const settled = reviewPrompt.hasLines || reviewPrompt.reviewed;

    useEffect(() => {
        if (checkedArrival.current) {
            return;
        }

        checkedArrival.current = true;

        if (canReview && !settled && !reviewWasSkipped(userId)) {
            setOpen(true);
        }
    }, [canReview, settled, userId]);

    return {
        open,
        step,
        prompt: { ...reviewPrompt, hasLines: settled },
        openAt: (at: ForecastReviewStep) => {
            setStep(at);
            setOpen(true);
        },
        onOpenChange: (next: boolean) => {
            if (!next && !settled) {
                rememberReviewSkipped(userId);
            }

            setOpen(next);
        },
        onSaved: () => setOpen(false),
        onStartedOver: () => {
            forgetReviewSkipped(userId);
            setStep(1);
            setOpen(true);
        },
    };
}

/** The page of a user with no line yet: everything is estimated. */
function NoLinesYet({ onReview }: { onReview: () => void }) {
    return (
        <Card data-test="forecast-no-lines">
            <CardContent>
                <EmptyState size="inline">
                    <EmptyStateTitle>
                        {__('Your forecast is only an estimate for now')}
                    </EmptyStateTitle>
                    <EmptyStateDescription>
                        {__(
                            'Check how we have estimated each category from your last months and add your yearly payments, so the forecast does not miss what you already know.',
                        )}
                    </EmptyStateDescription>
                    <Button
                        onClick={onReview}
                        data-test="forecast-start-review"
                    >
                        {__('Review your forecast')}
                    </Button>
                </EmptyState>
            </CardContent>
        </Card>
    );
}

interface ReviewProps {
    reviewPrompt: ForecastReviewPrompt;
    newSuggestionCount: number | undefined;
    onOpenReview: (step: ForecastReviewStep) => void;
}

/** "Yearly payments found (2 new)", over the plan once the user has lines. */
function ReviewLink({
    reviewPrompt,
    newSuggestionCount,
    onOpenReview,
}: ReviewProps) {
    if (!newSuggestionCount || !reviewPrompt.hasLines) {
        return null;
    }

    return (
        <button
            type="button"
            className="text-[13px] underline underline-offset-4 hover:text-foreground/80"
            onClick={() => onOpenReview(2)}
            data-test="forecast-review-link"
        >
            {newSuggestionCount === 1
                ? __('Yearly payments found (1 new)')
                : __('Yearly payments found (:count new)', {
                      count: newSuggestionCount,
                  })}
        </button>
    );
}

/** The page of a user with no line yet, which leads to the review. */
function NoLinesPrompt({ reviewPrompt, onOpenReview }: ReviewProps) {
    if (reviewPrompt.hasLines) {
        return null;
    }

    return <NoLinesYet onReview={() => onOpenReview(1)} />;
}

function ForecastContent({
    forecast,
    ...review
}: ReviewProps & { forecast: ForecastData }) {
    const { reviewPrompt, newSuggestionCount, onOpenReview } = review;
    const [selectedMonth, setSelectedMonth] = useState(() =>
        defaultMonth(forecast),
    );
    const planRef = useRef<HTMLDivElement>(null);

    return (
        <>
            <NoLinesPrompt {...review} />
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
                        <ReviewLink
                            reviewPrompt={reviewPrompt}
                            newSuggestionCount={newSuggestionCount}
                            onOpenReview={onOpenReview}
                        />
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

/** The forecast on a phone, of one account or of all of them: one month at a time. */
function MobileForecastContent({
    forecast,
    ...review
}: ReviewProps & { forecast: ForecastData | ForecastAllData }) {
    const [selectedMonth, setSelectedMonth] = useState(() =>
        defaultMonth(forecast),
    );

    return (
        <>
            <NoLinesPrompt {...review} />
            <ForecastMobileView
                forecast={forecast}
                selectedMonth={selectedMonth}
                onSelectMonth={setSelectedMonth}
                reviewAction={<ReviewLink {...review} />}
            />
        </>
    );
}

/**
 * Every account together: the total, where its rise comes from, the plan by
 * category across the accounts and how each account closes.
 */
function ForecastAllContent({
    forecast,
    ...review
}: ReviewProps & { forecast: ForecastAllData }) {
    const [selectedMonth, setSelectedMonth] = useState(() =>
        defaultMonth(forecast),
    );

    return (
        <>
            <NoLinesPrompt {...review} />
            <ForecastAccountTabs
                accounts={forecast.accounts}
                selectedId={null}
                horizon={forecast.horizon}
            />
            <ForecastAllAlerts forecast={forecast} />
            <ForecastAllKpis forecast={forecast} />
            <ForecastChart
                forecast={forecast}
                selectedMonth={selectedMonth}
                onSelectMonth={setSelectedMonth}
            />
            <ForecastAllPlanTable
                forecast={forecast}
                selectedMonth={selectedMonth}
                headerAction={<ReviewLink {...review} />}
            />
            <p className="text-xs text-muted-foreground">
                {__(
                    'Transfers between your accounts go apart: they change the balance of each account but not the total. The growth uses the yearly rate of each investment account, or the trend of its last 12 months when it only has balances. The likely range applies to the estimates and those trends only.',
                )}
            </p>
        </>
    );
}

function isEmpty(
    forecast: ForecastPageProps['forecast'],
): forecast is ForecastEmpty {
    return forecast.accounts.length === 0;
}

/** The edit sheets, once there is an account to edit. */
function EditorBoundary({
    forecast,
    projectionInput,
    children,
}: ForecastPageProps & { children: ReactNode }) {
    if (isEmpty(forecast)) {
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
    recurringCharges,
}: ForecastPageProps) {
    const reviewState = useReview(reviewPrompt, !isEmpty(forecast));
    const isMobile = useIsMobile();
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
                        description={
                            isMobile
                                ? undefined
                                : __(
                                      'How you are going to be, from what you know and what you usually spend. The more you make concrete, the more reliable it is.',
                                  )
                        }
                        className={
                            isMobile ? 'flex-nowrap items-center' : undefined
                        }
                        actions={
                            isMobile ? (
                                <div className="flex items-center gap-2">
                                    {!isEmpty(forecast) && (
                                        <ForecastStartOverMenu
                                            className="size-10 rounded-full"
                                            onStartedOver={
                                                reviewState.onStartedOver
                                            }
                                        />
                                    )}
                                    <ForecastMobileAddButton />
                                </div>
                            ) : (
                                <div className="flex flex-wrap items-center gap-2">
                                    <ForecastHorizonPicker
                                        horizon={forecast.horizon}
                                        accountId={
                                            forecast.scope === 'all'
                                                ? 'all'
                                                : (forecast.account?.id ?? null)
                                        }
                                    />
                                    <AddButton />
                                    {!isEmpty(forecast) && (
                                        <ForecastStartOverMenu
                                            onStartedOver={
                                                reviewState.onStartedOver
                                            }
                                        />
                                    )}
                                </div>
                            )
                        }
                    />
                    {isEmpty(forecast) ? (
                        <Card>
                            <CardContent>
                                <EmptyState>
                                    <EmptyStateTitle>
                                        {__('No accounts to forecast')}
                                    </EmptyStateTitle>
                                    <EmptyStateDescription>
                                        {forecast.accountChoices.length > 0
                                            ? __(
                                                  'None of your accounts is in the forecast: credit cards, loans and properties are left out unless you include them. Tick one to see how its balance is going to evolve.',
                                              )
                                            : __(
                                                  'The forecast covers the accounts of this space that are not archived. Add one to see how its balance is going to evolve.',
                                              )}
                                    </EmptyStateDescription>
                                    {forecast.accountChoices.length > 0 ? (
                                        <ForecastAccountChoices
                                            choices={forecast.accountChoices}
                                        />
                                    ) : (
                                        <Button asChild variant="outline">
                                            <Link href={accountsList()}>
                                                {__('Go to accounts')}
                                            </Link>
                                        </Button>
                                    )}
                                </EmptyState>
                            </CardContent>
                        </Card>
                    ) : isMobile ? (
                        <MobileForecastContent
                            key={`${forecast.scope === 'all' ? 'all' : forecast.account.id}:${forecast.horizon}`}
                            forecast={forecast}
                            reviewPrompt={reviewState.prompt}
                            newSuggestionCount={newSuggestionCount}
                            onOpenReview={reviewState.openAt}
                        />
                    ) : forecast.scope === 'all' ? (
                        <ForecastAllContent
                            key={`all:${forecast.horizon}`}
                            forecast={forecast}
                            reviewPrompt={reviewState.prompt}
                            newSuggestionCount={newSuggestionCount}
                            onOpenReview={reviewState.openAt}
                        />
                    ) : (
                        <ForecastContent
                            key={`${forecast.account.id}:${forecast.horizon}`}
                            forecast={forecast}
                            reviewPrompt={reviewState.prompt}
                            newSuggestionCount={newSuggestionCount}
                            onOpenReview={reviewState.openAt}
                        />
                    )}
                    {!isEmpty(forecast) && (
                        <ForecastRecurringCard
                            recurringCharges={recurringCharges}
                        />
                    )}
                    {!isEmpty(forecast) && (
                        <ForecastReviewDialog
                            open={reviewState.open}
                            onOpenChange={reviewState.onOpenChange}
                            onSaved={reviewState.onSaved}
                            isPro={forecast.isPro}
                            review={review}
                            initialStep={reviewState.step}
                            defaultAccountId={
                                forecast.scope === 'all'
                                    ? (forecast.alert.accountId ??
                                      forecast.accounts[0].id)
                                    : forecast.account.id
                            }
                        />
                    )}
                </div>
            </EditorBoundary>
        </AppSidebarLayout>
    );
}
