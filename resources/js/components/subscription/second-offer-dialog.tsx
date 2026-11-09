import { UpgradeDialog } from '@/components/subscription/upgrade-dialog';
import { useOfferedPriceTier } from '@/hooks/use-price-tier';
import { captureEvent } from '@/lib/posthog';
import { shown } from '@/routes/subscribe/second-offer';
import { type SharedData } from '@/types';
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
 * Pro, offered once more to a free user whose first transactions just landed:
 * they met the paywall before the app had anything of theirs to show. Drawn by
 * the app shell, so it opens wherever they land after the import; the server
 * decides who qualifies (`User::isEligibleForSecondOffer()`).
 *
 * The latam price has no bank connection, so its copy sells the AI and the
 * direct integrations instead of a bank sync.
 */
export function SecondOfferDialog() {
    const { showSecondOffer } = usePage<SharedData>().props;
    const tier = useOfferedPriceTier();
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (!showSecondOffer || openedThisSession) {
            return;
        }

        openedThisSession = true;
        setOpen(true);
        captureEvent('second_offer_shown', { price_tier: tier });
        // Nothing to do with the answer: if it never lands, the offer simply
        // comes back on the next page load.
        void axios.post(shown.url()).catch(() => {});
    }, [showSecondOffer, tier]);

    function handleOpenChange(next: boolean): void {
        if (!next) {
            captureEvent('second_offer_dismissed', { price_tier: tier });
        }

        setOpen(next);
    }

    return (
        <UpgradeDialog
            open={open}
            onOpenChange={handleOpenChange}
            title={__('Your transactions are in')}
            description={
                tier === 'latam'
                    ? __(
                          'Let Pro categorize them with AI and keep your direct integrations in sync, so new transactions arrive on their own.',
                      )
                    : __(
                          'Let Pro categorize them with AI and sync your bank automatically, so new transactions arrive on their own.',
                      )
            }
            source="second_offer"
        />
    );
}
