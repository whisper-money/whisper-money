import { StepBadge, StepSectionLabel } from '@/components/onboarding/step-list';
import { UpgradeDialog } from '@/components/subscription/upgrade-dialog';
import { useLocale } from '@/hooks/use-locale';
import { useOfferedPriceTier } from '@/hooks/use-price-tier';
import { captureEvent } from '@/lib/posthog';
import { shown } from '@/routes/subscribe/second-offer';
import { type SecondOfferBacklog, type SharedData } from '@/types';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';

/**
 * Once per page load at most. The shell remounts on every visit, and the
 * server only stops offering it once the "shown" stamp lands, so without the
 * latch a quick navigation could open it twice.
 */
let openedThisSession = false;

/**
 * The plan, offered once more to a free user whose first transactions just
 * landed: they met the paywall before the app had anything of theirs to show.
 * Drawn by the app shell, so it opens wherever they land after the import; the
 * server decides who qualifies (`User::isEligibleForSecondOffer()`) and hands
 * down the uncategorized backlog the copy points at.
 */
export function SecondOfferDialog() {
    const { showSecondOffer, secondOfferBacklog } = usePage<SharedData>().props;
    const tier = useOfferedPriceTier();
    const [open, setOpen] = useState(false);
    // Kept as it was when the dialog opened: the "shown" stamp makes the user
    // ineligible, so the next visit shares no backlog, and the open dialog
    // must not switch its copy or drop its preview under them.
    const [backlog, setBacklog] = useState<SecondOfferBacklog | null>(null);

    useEffect(() => {
        if (!showSecondOffer || openedThisSession) {
            return;
        }

        openedThisSession = true;
        setBacklog(secondOfferBacklog);
        setOpen(true);
        captureEvent('second_offer_shown', { price_tier: tier });
        // Nothing to do with the answer: if it never lands, the offer simply
        // comes back on the next page load.
        void axios.post(shown.url()).catch(() => {});
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [showSecondOffer, tier]);

    function handleOpenChange(next: boolean): void {
        if (!next) {
            captureEvent('second_offer_dismissed', { price_tier: tier });
        }

        setOpen(next);
    }

    const recent = backlog?.recent ?? [];

    return (
        <UpgradeDialog
            open={open}
            onOpenChange={handleOpenChange}
            source="second_offer"
            uncategorizedCount={backlog?.count ?? 0}
        >
            {recent.length > 0 ? (
                <WaitingForACategory movements={recent} />
            ) : undefined}
        </UpgradeDialog>
    );
}

/**
 * The user's own latest uncategorized movements, as they are now. Nothing is
 * guessed for them: without a plan there is no AI run and no consent to send
 * them anywhere, so the preview only shows what is waiting.
 */
function WaitingForACategory({
    movements,
}: {
    movements: SecondOfferBacklog['recent'];
}) {
    const locale = useLocale();

    return (
        <div className="flex flex-col">
            <StepSectionLabel>{__('Waiting for a category')}</StepSectionLabel>
            <ul className="flex flex-col divide-y rounded-lg border">
                {movements.map((movement) => (
                    <li
                        key={movement.id}
                        className="flex items-center justify-between gap-3 px-3.5 py-3"
                    >
                        <span className="flex min-w-0 flex-col gap-0.5">
                            <span className="truncate text-sm font-medium">
                                {movement.description}
                            </span>
                            <span className="text-[13px] text-muted-foreground">
                                {formatCurrency(
                                    movement.amount,
                                    movement.currency_code,
                                    locale,
                                )}
                            </span>
                        </span>
                        <StepBadge>{__('Needs a category')}</StepBadge>
                    </li>
                ))}
            </ul>
        </div>
    );
}
