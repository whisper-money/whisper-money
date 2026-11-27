import { store } from '@/actions/App/Http/Controllers/Forecast/ForecastReviewController';
import { ForecastReviewAnnualStep } from '@/components/forecast/forecast-review-annual-step';
import { ForecastReviewEstimatesStep } from '@/components/forecast/forecast-review-estimates-step';
import { ForecastReviewUncategorized } from '@/components/forecast/forecast-review-uncategorized';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Sheet, SheetContent } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useIsMobile } from '@/hooks/use-mobile';
import {
    incompletePayment,
    reviewAnswers,
    type ManualPaymentDraft,
    type ReviewError,
} from '@/lib/forecast/review';
import { type ForecastReview } from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export type ForecastReviewStep = 1 | 2;

interface ForecastReviewDialogProps {
    open: boolean;
    /** Closing it this way leaves everything as it was. */
    onOpenChange: (open: boolean) => void;
    /** The answers were stored: the page reloads with the new lines. */
    onSaved: () => void;
    isPro: boolean;
    /** Undefined until the page loads it: opening the dialog asks for it. */
    review: ForecastReview | undefined;
    initialStep?: ForecastReviewStep;
    /** The account the payments typed in go to unless the user picks another. */
    defaultAccountId: string | null;
}

/**
 * The forecast's review in two steps, how each category is estimated and
 * then the yearly payments, after a notice when too much money has no
 * category: a dialog on a computer, the whole screen on a phone. A figure
 * changed in the first step is stored at once; the yearly payments only
 * when the last step is sent. "Not now", Esc or the close button leave
 * them out.
 */
export function ForecastReviewDialog({
    open,
    onOpenChange,
    onSaved,
    isPro,
    review,
    initialStep = 1,
    defaultAccountId,
}: ForecastReviewDialogProps) {
    const isMobile = useIsMobile();

    useEffect(() => {
        if (open && review === undefined) {
            router.reload({ only: ['review'] });
        }
    }, [open, review]);

    const content = review ? (
        <ReviewFlow
            // New suggestions (after "Load them again") start a new review.
            key={review.annual.map((suggestion) => suggestion.key).join(',')}
            review={review}
            initialStep={initialStep}
            defaultAccountId={defaultAccountId}
            isPro={isPro}
            onSkip={() => onOpenChange(false)}
            onSaved={onSaved}
        />
    ) : (
        <ReviewLoading />
    );

    if (isMobile) {
        return (
            <Sheet open={open} onOpenChange={onOpenChange}>
                <SheetContent
                    side="bottom"
                    className="inset-0 h-dvh gap-0 border-0 p-0"
                    data-test="forecast-review"
                >
                    {content}
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="flex h-[min(860px,90vh)] flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl"
                data-test="forecast-review"
            >
                {content}
            </DialogContent>
        </Dialog>
    );
}

function ReviewLoading() {
    return (
        <div className="flex flex-col gap-3 p-6" aria-busy="true">
            <DialogTitle className="sr-only">
                {__('Loading the review')}
            </DialogTitle>
            <Skeleton className="h-4 w-40" />
            <Skeleton className="h-7 w-3/4" />
            <Skeleton className="h-4 w-full" />
            {[0, 1, 2, 3, 4].map((row) => (
                <Skeleton key={row} className="h-14 w-full" />
            ))}
        </div>
    );
}

function ReviewFlow({
    review,
    initialStep,
    defaultAccountId,
    isPro,
    onSkip,
    onSaved,
}: {
    review: ForecastReview;
    initialStep: ForecastReviewStep;
    defaultAccountId: string | null;
    isPro: boolean;
    onSkip: () => void;
    onSaved: () => void;
}) {
    const [step, setStep] = useState<ForecastReviewStep>(initialStep);
    // Shown once, before the first step, and not again once past it.
    const [noticeSeen, setNoticeSeen] = useState(initialStep !== 1);
    const [addedAnnual, setAddedAnnual] = useState<Set<string>>(new Set());
    const [manual, setManual] = useState<ManualPaymentDraft[]>([]);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<ReviewError | null>(null);

    const finish = () => {
        const incomplete = incompletePayment(manual);

        if (incomplete) {
            setError({
                message: incomplete.name.trim()
                    ? __('Add the amount of :name, or remove it.', {
                          name: incomplete.name,
                      })
                    : __('Name the payment you added, or remove it.'),
                stale: false,
            });

            return;
        }

        setProcessing(true);
        setError(null);
        router.post(
            store.url(),
            // Spread into a plain object: Inertia's payload type is a record.
            { ...reviewAnswers(review.annual, addedAnnual, manual) },
            {
                preserveScroll: true,
                onSuccess: onSaved,
                onError: (errors) =>
                    setError({
                        message: errors.annual
                            ? errors.annual
                            : __('We could not save your answers. Try again.'),
                        stale: Boolean(errors.annual),
                    }),
                onFinish: () => setProcessing(false),
            },
        );
    };

    if (step === 1 && !noticeSeen && review.uncategorized) {
        return (
            <ForecastReviewUncategorized
                uncategorized={review.uncategorized}
                onSkip={onSkip}
                onContinue={() => setNoticeSeen(true)}
            />
        );
    }

    if (step === 1) {
        return (
            <ForecastReviewEstimatesStep
                estimates={review.estimates}
                showAccounts={
                    review.accountId === null && review.accounts.length > 1
                }
                onSkip={onSkip}
                onNext={() => {
                    setNoticeSeen(true);
                    setStep(2);
                }}
            />
        );
    }

    return (
        <ForecastReviewAnnualStep
            review={review}
            added={addedAnnual}
            onAddedChange={setAddedAnnual}
            manual={manual}
            onManualChange={setManual}
            defaultAccountId={
                defaultAccountId ?? review.accounts[0]?.id ?? null
            }
            processing={processing}
            error={error}
            isPro={isPro}
            onReload={() => router.reload({ only: ['review'] })}
            onBack={() => setStep(1)}
            onFinish={finish}
        />
    );
}
