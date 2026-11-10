import { Button } from '@/components/ui/button';
import {
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { roadmap } from '@/routes';
import { index as connections } from '@/routes/settings/connections';
import { formatCurrency } from '@/utils/currency';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import {
    LifeBuoyIcon,
    MapIcon,
    PercentIcon,
    RepeatIcon,
    type LucideIcon,
} from 'lucide-react';
import { type ReactNode } from 'react';

/**
 * What the server offers for the reason given (`CancellationOffers`). A new
 * offer (pausing, once it exists) adds its member here and its card in
 * `OfferStep`; the exhaustive switches fail to type-check until it does.
 */
export type CancellationOffer =
    | {
          offer: 'retention_coupon';
          percentOff: number;
          months: number;
          savedAmount: number;
      }
    | { offer: 'switch_to_yearly'; price: number; savings: number }
    | { offer: 'roadmap' }
    | { offer: 'support' };

/**
 * One offer, and always the plain way past it. The offers that keep the plan
 * running (a discount, the yearly price) are taken here; the ones that only
 * point at help leave the user free to come back and cancel.
 */
export function OfferStep({
    offer,
    onTrial,
    endsAt,
    currency,
    locale,
    processing,
    onAccept,
    onDecline,
}: {
    offer: CancellationOffer;
    onTrial: boolean;
    endsAt: string | null;
    currency: string;
    locale: string;
    processing: boolean;
    onAccept: (
        offer: CancellationOffer,
        action?: 'contact' | 'connections',
    ) => void;
    onDecline: () => void;
}) {
    const money = (amount: number) =>
        formatCurrency(amount * 100, currency, locale);
    const firstCharge =
        onTrial && endsAt ? formatDateMedium(endsAt, locale) : null;
    const accept = () => onAccept(offer);
    const savingOfferButton = (label: string) => (
        <Button
            onClick={accept}
            disabled={processing}
            data-testid="cancel-flow-accept"
        >
            {processing && <Spinner />}
            {label}
        </Button>
    );

    const card = ((): OfferCardProps => {
        switch (offer.offer) {
            case 'retention_coupon':
                return {
                    icon: PercentIcon,
                    title: __(':percent% off for the next :months months', {
                        percent: String(offer.percentOff),
                        months: String(offer.months),
                    }),
                    body: firstCharge
                        ? __(
                              'Stay and save :amount. The discount starts with your first charge on :date.',
                              {
                                  amount: money(offer.savedAmount),
                                  date: firstCharge,
                              },
                          )
                        : __(
                              'Stay and save :amount on your next invoices. Nothing else about your plan changes.',
                              { amount: money(offer.savedAmount) },
                          ),
                    action: savingOfferButton(__('Apply the discount')),
                };
            case 'switch_to_yearly':
                return {
                    icon: RepeatIcon,
                    title: __('Pay yearly and save :amount', {
                        amount: money(offer.savings),
                    }),
                    body: firstCharge
                        ? __(
                              'The yearly plan is :price a year, :savings less than paying monthly. You pay it when your trial ends, on :date.',
                              {
                                  price: money(offer.price),
                                  savings: money(offer.savings),
                                  date: firstCharge,
                              },
                          )
                        : __(
                              'The yearly plan is :price a year, :savings less than paying monthly. It is charged today, minus what is left of this month.',
                              {
                                  price: money(offer.price),
                                  savings: money(offer.savings),
                              },
                          ),
                    action: savingOfferButton(__('Switch to yearly')),
                };
            case 'roadmap':
                return {
                    icon: MapIcon,
                    title: __('Thanks, we read every answer'),
                    body: __(
                        'See what we are building next on the roadmap, and vote for what you need most.',
                    ),
                    action: (
                        <Button variant="outline" asChild>
                            <a
                                href={roadmap.url()}
                                target="_blank"
                                rel="noopener noreferrer"
                                onClick={accept}
                                data-testid="cancel-flow-accept"
                            >
                                {__('Open the roadmap')}
                            </a>
                        </Button>
                    ),
                };
            case 'support':
                return {
                    icon: LifeBuoyIcon,
                    title: __('Let us help you sort it out'),
                    body: __(
                        'Most bank and setup problems are fixed in a few messages. Write to us, or check your bank connections first.',
                    ),
                    action: (
                        <>
                            <Button variant="outline" asChild>
                                <Link
                                    href={connections.url()}
                                    onClick={() =>
                                        onAccept(offer, 'connections')
                                    }
                                    data-testid="cancel-flow-connections"
                                >
                                    {__('Check bank connections')}
                                </Link>
                            </Button>
                            <Button
                                onClick={() => onAccept(offer, 'contact')}
                                data-testid="cancel-flow-accept"
                            >
                                {__('Get help')}
                            </Button>
                        </>
                    ),
                };
        }
    })();

    return (
        <OfferCard {...card} onDecline={onDecline} processing={processing} />
    );
}

interface OfferCardProps {
    icon: LucideIcon;
    title: string;
    body: string;
    action: ReactNode;
}

function OfferCard({
    icon: Icon,
    title,
    body,
    action,
    onDecline,
    processing,
}: OfferCardProps & { onDecline: () => void; processing: boolean }) {
    return (
        <>
            <DialogHeader>
                <div className="mb-2 flex size-10 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                    <Icon className="size-5 text-emerald-600 dark:text-emerald-400" />
                </div>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{body}</DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button
                    variant="ghost"
                    onClick={onDecline}
                    disabled={processing}
                    data-testid="cancel-flow-decline"
                >
                    {__('No thanks, continue cancelling')}
                </Button>
                {action}
            </DialogFooter>
        </>
    );
}
