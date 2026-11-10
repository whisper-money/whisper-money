import { Button } from '@/components/ui/button';
import {
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { roadmap } from '@/routes';
import { index as connections } from '@/routes/settings/connections';
import { formatCurrency } from '@/utils/currency';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import {
    LifeBuoyIcon,
    MapIcon,
    PauseIcon,
    PercentIcon,
    RepeatIcon,
    type LucideIcon,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

/** One pause length the server offers, and the day it would end. */
export interface PauseOption {
    months: number;
    resumesAt: string;
}

/**
 * What the server offers for the reason given (`CancellationOffers`). A new
 * offer adds its member here and its card in `OfferStep`; the exhaustive
 * switches fail to type-check until it does.
 */
export type CancellationOffer =
    | {
          offer: 'retention_coupon';
          percentOff: number;
          months: number;
          savedAmount: number;
      }
    | { offer: 'switch_to_yearly'; price: number; savings: number }
    | {
          offer: 'pause';
          /** The end of the period already paid for, when the pause starts. */
          startsAt: string;
          /** The monthly price the plan resumes at. */
          price: number;
          options: PauseOption[];
      }
    | { offer: 'roadmap' }
    | { offer: 'support' };

/**
 * How an offer was taken: which of a help offer's two ways (the support
 * dialog, or the link to the bank connections), or how long a pause.
 */
export interface OfferAcceptance {
    action?: 'contact' | 'connections';
    months?: number;
}

export type OfferAcceptHandler = (
    offer: CancellationOffer,
    acceptance?: OfferAcceptance,
) => void;

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
    onAccept: OfferAcceptHandler;
    onDecline: () => void;
}) {
    const money = (amount: number) =>
        formatCurrency(amount * 100, currency, locale);

    if (offer.offer === 'pause') {
        return (
            <PauseOfferStep
                offer={offer}
                money={money}
                locale={locale}
                processing={processing}
                onAccept={(months) => onAccept(offer, { months })}
                onDecline={onDecline}
            />
        );
    }

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
                                        onAccept(offer, {
                                            action: 'connections',
                                        })
                                    }
                                    data-testid="cancel-flow-connections"
                                >
                                    {__('Check bank connections')}
                                </Link>
                            </Button>
                            <Button
                                onClick={() =>
                                    onAccept(offer, { action: 'contact' })
                                }
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

/**
 * A few months off instead of cancelling, for someone not using the app
 * enough: the plan stays on until the paid period ends, nothing is charged
 * while paused, and it resumes on its own. One month unless they pick more.
 */
function PauseOfferStep({
    offer,
    money,
    locale,
    processing,
    onAccept,
    onDecline,
}: {
    offer: Extract<CancellationOffer, { offer: 'pause' }>;
    money: (amount: number) => string;
    locale: string;
    processing: boolean;
    onAccept: (months: number) => void;
    onDecline: () => void;
}) {
    const [months, setMonths] = useState(offer.options[0]?.months ?? 1);
    const option =
        offer.options.find((candidate) => candidate.months === months) ??
        offer.options[0];
    const monthsLabel = (count: number) =>
        count === 1
            ? __('1 month')
            : __(':count months', { count: String(count) });

    return (
        <OfferCard
            icon={PauseIcon}
            title={__('Pause your plan instead')}
            body={__(
                'Take a break without losing anything. Standard stays on until :date, then nothing is charged and bank sync and AI categorization rest until the pause ends. Everything you set up stays.',
                { date: formatDateMedium(offer.startsAt, locale) },
            )}
            action={
                <Button
                    onClick={() => onAccept(months)}
                    disabled={processing}
                    data-testid="cancel-flow-accept"
                >
                    {processing && <Spinner />}
                    {months === 1
                        ? __('Pause for 1 month')
                        : __('Pause for :count months', {
                              count: String(months),
                          })}
                </Button>
            }
            onDecline={onDecline}
            processing={processing}
        >
            <div className="flex flex-col gap-3">
                <ToggleGroup
                    type="single"
                    value={String(months)}
                    onValueChange={(value) => value && setMonths(Number(value))}
                    variant="outline"
                    className="w-full"
                    aria-label={__('How long to pause')}
                    disabled={processing}
                >
                    {offer.options.map((candidate) => (
                        <ToggleGroupItem
                            key={candidate.months}
                            value={String(candidate.months)}
                            className="flex-1 cursor-pointer aria-checked:bg-primary/10"
                            data-testid={`cancel-flow-pause-${candidate.months}`}
                        >
                            {monthsLabel(candidate.months)}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
                {option && (
                    <p
                        className="text-sm text-muted-foreground"
                        data-testid="cancel-flow-pause-resumes"
                    >
                        {__(
                            'Resumes on its own on :date at :price a month. We will email you a few days before.',
                            {
                                date: formatDateMedium(
                                    option.resumesAt,
                                    locale,
                                ),
                                price: money(offer.price),
                            },
                        )}
                    </p>
                )}
            </div>
        </OfferCard>
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
    children,
}: OfferCardProps & {
    onDecline: () => void;
    processing: boolean;
    /** Anything the offer asks before it is taken, under its description. */
    children?: ReactNode;
}) {
    return (
        <>
            <DialogHeader>
                <div className="mb-2 flex size-10 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                    <Icon className="size-5 text-emerald-600 dark:text-emerald-400" />
                </div>
                <DialogTitle className="pr-6">{title}</DialogTitle>
                <DialogDescription>{body}</DialogDescription>
            </DialogHeader>
            {children}
            {/* The offer's own actions on one row, right-aligned and free to
                wrap (the help offer has two); the way past it on a row of its
                own, so it can never be pushed out of the dialog. */}
            <div className="flex flex-col gap-3">
                <DialogFooter className="sm:flex-wrap">{action}</DialogFooter>
                <Button
                    variant="link"
                    className="h-auto self-center p-0 text-muted-foreground sm:self-end"
                    onClick={onDecline}
                    disabled={processing}
                    data-testid="cancel-flow-decline"
                >
                    {__('No thanks, continue cancelling')}
                </Button>
            </div>
        </>
    );
}
