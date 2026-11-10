import { StepButton } from '@/components/onboarding/step-button';
import { AiDisclosure } from '@/components/onboarding/step-gate';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import { StepNote } from '@/components/onboarding/step-screen';
import { PlanPicker, planTerms } from '@/components/subscription/plan-picker';
import {
    checkoutHref,
    checkoutLabel,
} from '@/components/subscription/subscription-offer';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    useOfferedPriceTier,
    usePriceTierChoice,
} from '@/hooks/use-price-tier';
import { captureEvent } from '@/lib/posthog';
import { type PriceTier } from '@/lib/price-tier';
import { billing } from '@/routes/settings';
import { type SharedData } from '@/types';
import { type Plan } from '@/types/pricing';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { type ReactNode, useState } from 'react';

/**
 * The upsell entry point a checkout starts from. Mirrors the PHP
 * App\Enums\UpsellSource so revenue can be attributed per point.
 */
export type UpsellSource =
    | 'ai_categorization'
    | 'connections'
    | 'accounts'
    | 'second_offer'
    | 'api'
    | 'mcp';

/** The upsell points whose copy sells a bank connection. */
const BANK_UPSELL_SOURCES: UpsellSource[] = ['connections', 'accounts'];

interface UpsellCopy {
    title: string;
    description: string;
}

/**
 * What each upsell point argues: the benefit the user reached for, named the
 * way they reached for it. The plan is called Standard everywhere it is sold.
 *
 * The second offer names the user's own backlog, so it needs the count; the
 * latam price has no bank connection, so its copy sells the direct
 * integrations instead.
 */
function upsellCopy(
    source: UpsellSource,
    offeredTier: PriceTier,
    uncategorizedCount: number,
): UpsellCopy {
    switch (source) {
        case 'ai_categorization':
            return {
                title: __('Let the AI sort your movements'),
                description: __(
                    'Standard files every new movement for you and writes the rules that keep doing it. It switches on with your plan — no extra step.',
                ),
            };
        case 'connections':
        case 'accounts':
            return {
                title: __('Connect your bank and stop typing movements'),
                description: __(
                    'Standard keeps your accounts in sync, so new movements arrive on their own every day.',
                ),
            };
        case 'api':
            return {
                title: __('Build on your data with Standard'),
                description: __(
                    'Create API tokens for your own scripts and integrations.',
                ),
            };
        case 'mcp':
            return {
                title: __('Build on your data with Standard'),
                description: __(
                    'Create tokens to connect AI assistants to your data.',
                ),
            };
        case 'second_offer':
            return secondOfferCopy(offeredTier, uncategorizedCount);
    }
}

function secondOfferCopy(
    offeredTier: PriceTier,
    uncategorizedCount: number,
): UpsellCopy {
    const latam = offeredTier === 'latam';

    // Everything already has a category (rules, or the user by hand): there
    // is no backlog to point at, only the movements still to come.
    if (uncategorizedCount === 0) {
        return {
            title: __('Your movements are in'),
            description: latam
                ? __(
                      'Standard sorts new ones with AI and keeps your direct integrations in sync, so they arrive on their own.',
                  )
                : __(
                      'Standard sorts new ones with AI and keeps your bank in sync, so they arrive on their own.',
                  ),
        };
    }

    return {
        title:
            uncategorizedCount === 1
                ? __('1 of your movements needs a category')
                : __(':count of your movements need a category', {
                      count: uncategorizedCount,
                  }),
        description: latam
            ? __(
                  'Standard’s AI files them in about a minute and keeps your direct integrations in sync, so new ones arrive already sorted.',
              )
            : __(
                  'Standard’s AI files them in about a minute and keeps your bank in sync, so new ones arrive already sorted.',
              ),
    };
}

/** The checkout button's label: what it starts, and that it starts free. */
function ctaLabel(source: UpsellSource, plan: Plan | undefined): string {
    if (source === 'second_offer' && plan) {
        return __('Sort them — free for :days days', {
            days: plan.trial_days,
        });
    }

    return checkoutLabel(plan);
}

/**
 * A contextual upsell dialog with a plan picker that starts Stripe checkout.
 * Reused across upsell points (AI categorization, bank connections, connected
 * accounts, the API and MCP settings, the second offer after a first import);
 * each passes only its `source`, and the dialog writes the copy for it.
 *
 * The AI categorization point and the second offer carry the same disclosure
 * row as the onboarding AI gate: their checkout switches the consent on with the plan
 * (`UpsellSource::grantsAiConsent()`), which is only honest when the screen
 * says what leaves the account.
 */
export function UpgradeDialog({
    open,
    onOpenChange,
    source,
    uncategorizedCount = 0,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    source: UpsellSource;
    /** How many movements still have no category. Only the second offer uses it. */
    uncategorizedCount?: number;
    /**
     * Drawn under the copy in place of the "rest of Standard" row, e.g. a
     * preview. Never in place of the AI disclosure, which has to stay on the
     * screen whose checkout grants the consent.
     */
    children?: ReactNode;
}) {
    const { auth, pricing, locale } = usePage<SharedData>().props;
    const offeredTier = useOfferedPriceTier();
    // The bank-connection upsells open on the price that includes a bank; the
    // latam one stays a click away for whoever wanted a direct integration.
    const tierChoice = usePriceTierChoice({
        startOnStandard: BANK_UPSELL_SOURCES.includes(source),
    });
    const [preselectedPlan] = useState(pricing.defaultPlan);
    const [selectedPlan, setSelectedPlan] = useState(preselectedPlan);
    const plan = tierChoice.plans[selectedPlan];
    const terms = planTerms(plan, pricing.currency, locale);
    const copy = upsellCopy(source, offeredTier, uncategorizedCount);
    // The sources whose checkout switches the AI consent on with the plan
    // (`UpsellSource::grantsAiConsent()`), so they have to say what leaves.
    const discloses =
        source === 'ai_categorization' || source === 'second_offer';
    // The latam price has no bank sync to promise.
    const sellsTheRest = !children && tierChoice.tier === 'standard';
    const rows =
        discloses || sellsTheRest ? (
            <StepList>
                {discloses && <AiDisclosure kind="ai" />}
                {sellsTheRest && (
                    <StepRow
                        icon={Check}
                        title={__('And the rest of Standard')}
                        description={__(
                            'Bank sync, unlimited accounts, daily updates',
                        )}
                    />
                )}
            </StepList>
        ) : null;

    // Someone who paused is not sold the plan again: the way back is resuming.
    if (auth?.pausedSubscription) {
        return (
            <PausedPlanDialog
                open={open}
                onOpenChange={onOpenChange}
                resumesAt={auth.pausedSubscription.resumesAt}
                locale={locale}
            />
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{copy.title}</DialogTitle>
                    <DialogDescription>{copy.description}</DialogDescription>
                </DialogHeader>

                {children}
                {rows}

                <PlanPicker
                    plans={tierChoice.plans}
                    currency={pricing.currency}
                    selectedPlan={selectedPlan}
                    onSelect={setSelectedPlan}
                    tierChoice={tierChoice}
                />

                <div className="flex flex-col gap-2.5">
                    <StepButton
                        text={ctaLabel(source, plan)}
                        href={checkoutHref(
                            selectedPlan,
                            preselectedPlan,
                            source,
                            tierChoice.tier,
                        )}
                        onClick={() =>
                            captureEvent('upgrade_checkout_started', {
                                source,
                                plan: selectedPlan,
                                preselected_plan: preselectedPlan,
                                price_tier: tierChoice.tier,
                            })
                        }
                    />

                    <StepButton
                        text={__('Maybe later')}
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                    />

                    {terms && <StepNote emphasis>{terms}</StepNote>}
                </div>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The upsell for a user inside a pause: what they reached for comes back when
 * the pause ends, or today from the billing page, where resuming says what it
 * charges.
 */
function PausedPlanDialog({
    open,
    onOpenChange,
    resumesAt,
    locale,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    resumesAt: string | null;
    locale: string;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent data-testid="paused-upgrade-dialog">
                <DialogHeader>
                    <DialogTitle>{__('Your plan is paused')}</DialogTitle>
                    <DialogDescription>
                        {resumesAt
                            ? __(
                                  'Standard comes back on :date. To have it back today, resume your plan from the billing page.',
                                  { date: formatDateMedium(resumesAt, locale) },
                              )
                            : __(
                                  'To have Standard back, resume your plan from the billing page.',
                              )}
                    </DialogDescription>
                </DialogHeader>
                <div className="flex flex-col gap-2.5">
                    <StepButton
                        text={__('See my plan')}
                        onClick={() => {
                            onOpenChange(false);
                            router.visit(billing.url());
                        }}
                    />
                    <StepButton
                        text={__('Maybe later')}
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                    />
                </div>
            </DialogContent>
        </Dialog>
    );
}
