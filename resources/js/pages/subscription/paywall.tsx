import { StepButton } from '@/components/onboarding/step-button';
import {
    StepList,
    StepRow,
    StepSectionLabel,
} from '@/components/onboarding/step-list';
import {
    StepCallout,
    StepNote,
    StepScreen,
} from '@/components/onboarding/step-screen';
import {
    FreePlanSurvey,
    type PaywallGate,
    useFreePlanSurvey,
} from '@/components/subscription/free-plan-survey';
import { PlanPicker, planTerms } from '@/components/subscription/plan-picker';
import {
    SubscriptionOffer,
    useOffer,
} from '@/components/subscription/subscription-offer';
import { SupportDialog } from '@/components/support-dialog';
import { useLocale } from '@/hooks/use-locale';
import { useOfferedPriceTier } from '@/hooks/use-price-tier';
import SubscriptionLayout from '@/layouts/subscription-layout';
import { captureEvent } from '@/lib/posthog';
import { type PriceTier } from '@/lib/price-tier';
import { dashboard } from '@/routes';
import { confirm as freePlanConfirm } from '@/routes/subscribe/free-plan';
import { type SharedData } from '@/types';
import { type Plan } from '@/types/pricing';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Check,
    CircleAlert,
    Landmark,
    LifeBuoy,
    ShieldCheck,
    Sparkles,
} from 'lucide-react';
import { useEffect, useState } from 'react';

interface PaywallStats {
    accountsCount: number;
    transactionsCount: number;
    categoriesCount: number;
    rulesCount: number;
    connectionsCount: number;
    /** Movements still without a category, for the soft gate's first row. */
    uncategorizedCount: number;
    /** Accounts with no bank behind them, kept up to date by hand. */
    manualAccountsCount: number;
    /** When the plan ended, for the screen that is about a plan that ended. */
    endedAt: string | null;
}

interface PaywallPageProps extends SharedData {
    stats: PaywallStats;
    canUseFreePlan: boolean;
    /**
     * Whether the delay after onboarding has run out, so the gates that have
     * something to disconnect may offer the free plan. Decided on the server —
     * the cut-off time never reaches the browser, because a user who is shown a
     * countdown is being told to wait rather than to choose a plan.
     */
    canEscapeToFreePlan: boolean;
    canManageConnectionsForFreePlan: boolean;
}

export default function Paywall() {
    const {
        auth,
        pricing,
        stats,
        canUseFreePlan,
        canEscapeToFreePlan,
        canManageConnectionsForFreePlan,
    } = usePage<PaywallPageProps>().props;

    const locale = useLocale();
    const [supportOpen, setSupportOpen] = useState(false);
    const survey = useFreePlanSurvey(() => router.visit(dashboard().url));

    const gate: PaywallGate = canManageConnectionsForFreePlan
        ? 'former-subscriber'
        : canUseFreePlan
          ? 'soft'
          : 'hard';

    // The soft gate is the personalized screen, told apart in the analytics
    // from the generic one it replaced; the other two gates are unchanged.
    const variant = gate === 'soft' ? { variant: 'personalized' } : {};

    const {
        selectedPlan,
        setSelectedPlan,
        plan,
        hasPlans,
        button,
        reminder,
        tierChoice,
    } = useOffer('paywall', {
        buttonText: gate === 'soft' ? softCheckoutLabel : undefined,
        onCheckout: (plan, preselectedPlan, priceTier) =>
            captureEvent('paywall_checkout_started', {
                gate,
                plan,
                preselected_plan: preselectedPlan,
                price_tier: priceTier,
                ...variant,
            }),
    });

    // The gates that have something to disconnect, once the delay after
    // onboarding is up. Derived once so the button and the screen it opens
    // cannot drift apart.
    const freeDoorOpen = gate !== 'soft' && canEscapeToFreePlan;

    // The tier first quoted, so conversion can be split by region: the
    // signed-in user's assigned one, not whichever they switch to afterwards.
    const offeredTier = useOfferedPriceTier();

    // This is the highest-leverage screen in the product and it carried no
    // instrumentation at all, so moving the escape hatch to first paint would
    // have shipped unmeasurable. `paywall_seen_at` is stamped on view, so it
    // cannot stand in for the choice.
    useEffect(() => {
        captureEvent('paywall_viewed', {
            gate,
            price_tier: offeredTier,
            ...(gate === 'soft' ? { variant: 'personalized' } : {}),
        });
    }, [gate, offeredTier]);

    if (!hasPlans) {
        return null;
    }

    const isFormer = gate !== 'soft';

    // The trial the picker opened on, so the headline does not change under
    // the user as they compare the two plans.
    const preselectedTrialDays =
        tierChoice.plans[pricing.defaultPlan]?.trial_days ?? 0;

    const title = isFormer
        ? __('Your plan ended')
        : __('Try Standard free for :days days', {
              days: preselectedTrialDays,
          });

    // The choice is made here and the event says so; the survey that opens
    // next only asks why, and every way out of it carries on to the dashboard.
    const continueFree = () => {
        captureEvent('paywall_free_plan_chosen');
        survey.ask();
    };

    const openFreePlanConfirmation = () => {
        captureEvent('paywall_free_plan_confirm_opened', { gate });
        router.visit(freePlanConfirm().url);
    };

    return (
        <SubscriptionLayout withAccountLinks>
            <Head title={title} />

            <StepScreen
                title={title}
                description={gateDescription(gate, stats, locale)}
                // Not pinned: a tall sticky footer painted over both plan rows
                // on any window under ~900px tall.
                footer={
                    <>
                        {button}
                        {/* The soft gate says it under its way out, after
                            the price it reminds about. */}
                        {gate !== 'soft' && reminder}

                        {/* On the soft gate the way out is here from the first
                            paint: there is nothing to disconnect and nothing to
                            warn about, so a timed reveal would only hide a
                            choice the user is entitled to make. The other two
                            gates are asking the user to give up the bank they
                            connected, which is a consequence that has to be
                            explained rather than offered in passing — so the
                            door waits out the delay after onboarding, leaving
                            room to choose a plan first. The wait itself is never
                            named on screen: the button is simply there or it is
                            not. */}
                        {gate === 'soft' ? (
                            <>
                                <StepButton
                                    text={__('Carry on free')}
                                    variant="ghost"
                                    onClick={continueFree}
                                    data-testid="carry-on-free"
                                />
                                <SoftTerms plan={plan} locale={locale} />
                                {reminder}
                            </>
                        ) : (
                            <>
                                {freeDoorOpen && (
                                    <StepButton
                                        text={__('Stay on the free plan')}
                                        variant="ghost"
                                        onClick={openFreePlanConfirmation}
                                        data-testid="carry-on-free"
                                    />
                                )}

                                <StepButton
                                    text={__('Need help?')}
                                    variant="ghost"
                                    icon={LifeBuoy}
                                    onClick={() => setSupportOpen(true)}
                                />
                            </>
                        )}
                    </>
                }
            >
                {isFormer ? (
                    <>
                        <WhatIsLeft stats={stats} locale={locale} />
                        <PlanPicker
                            plans={tierChoice.plans}
                            currency={pricing.currency}
                            selectedPlan={selectedPlan}
                            onSelect={setSelectedPlan}
                            tierChoice={tierChoice}
                        />
                    </>
                ) : (
                    <>
                        <WithYourData
                            stats={stats}
                            locale={locale}
                            tier={tierChoice.tier}
                        />
                        <SubscriptionOffer
                            selectedPlan={selectedPlan}
                            onSelect={setSelectedPlan}
                            tierChoice={tierChoice}
                        />
                        <StepCallout icon={ShieldCheck}>
                            {__('Your data is never sold or shared.')}
                        </StepCallout>
                    </>
                )}
            </StepScreen>

            {gate === 'soft' && (
                <FreePlanSurvey gate={gate} {...survey.surveyProps} />
            )}

            {isFormer && (
                <SupportDialog
                    open={supportOpen}
                    onOpenChange={setSupportOpen}
                    user={auth.user}
                />
            )}
        </SubscriptionLayout>
    );
}

/**
 * What a former subscriber still has, and what stopped. The first line is the
 * reassurance the screen exists to give — "your plan ended" reads as "you lost
 * your data" to most people — and the two below it are what starting again
 * would turn back on.
 */
function WhatIsLeft({
    stats,
    locale,
}: {
    stats: PaywallStats;
    locale: string;
}) {
    return (
        <StepList>
            <StepRow
                icon={Check}
                title={
                    stats.transactionsCount === 1
                        ? __('1 movement')
                        : __(':count movements', {
                              count: stats.transactionsCount.toLocaleString(
                                  locale,
                              ),
                          })
                }
                description={__('Still here, still categorised')}
            />
            {stats.accountsCount > 0 && (
                <StepRow
                    icon={CircleAlert}
                    title={
                        stats.accountsCount === 1
                            ? __('1 account')
                            : __(':count accounts', {
                                  count: stats.accountsCount,
                              })
                    }
                    description={__('No longer syncing')}
                />
            )}
            {stats.rulesCount > 0 && (
                <StepRow
                    icon={CircleAlert}
                    title={
                        stats.rulesCount === 1
                            ? __('1 rule')
                            : __(':count rules', { count: stats.rulesCount })
                    }
                    description={__('Paused until you come back')}
                />
            )}
        </StepList>
    );
}

/** The soft gate's button: the trial it starts, in the user's own words. */
function softCheckoutLabel(plan: Plan | undefined): string {
    return __('Start my :days-day free trial', { days: plan?.trial_days ?? 0 });
}

/**
 * What the soft gate's button commits to, under the way out: the price once the
 * trial is over, the same terms the upgrade dialog shows. The warning that
 * comes before the first charge follows it (`useOffer`'s `reminder`).
 */
function SoftTerms({
    plan,
    locale,
}: {
    plan: Plan | undefined;
    locale: string;
}) {
    const { pricing } = usePage<SharedData>().props;
    const terms = planTerms(plan, pricing.currency, locale);

    return terms ? <StepNote emphasis>{terms}</StepNote> : null;
}

/**
 * The soft gate's argument, made with the user's own data: the movements still
 * waiting for a category and the accounts they keep up to date by hand, each
 * only when there is one. A user with neither gets the general case instead —
 * what free keeps and what Standard adds.
 */
function WithYourData({
    stats,
    locale,
    tier,
}: {
    stats: PaywallStats;
    locale: string;
    tier: PriceTier;
}) {
    const { uncategorizedCount, manualAccountsCount } = stats;

    if (uncategorizedCount === 0 && manualAccountsCount === 0) {
        return (
            <StepList>
                <StepRow
                    icon={Check}
                    title={__('Free keeps working')}
                    description={__(
                        'Manual accounts, imports, categories, budgets, reports',
                    )}
                />
                <StepRow
                    icon={Sparkles}
                    title={__('Standard adds the tedious part')}
                    description={__(
                        'Bank sync, AI sorting, unlimited accounts',
                    )}
                />
            </StepList>
        );
    }

    return (
        <div className="flex flex-col">
            <StepSectionLabel>{__('With your data')}</StepSectionLabel>
            <StepList>
                {uncategorizedCount > 0 && (
                    <StepRow
                        icon={Sparkles}
                        title={
                            uncategorizedCount === 1
                                ? __('1 movement without a category')
                                : __(':count movements without a category', {
                                      count: uncategorizedCount.toLocaleString(
                                          locale,
                                      ),
                                  })
                        }
                        description={__(
                            'The AI files them in about a minute and writes the rules that keep doing it',
                        )}
                    />
                )}
                {manualAccountsCount > 0 && (
                    <StepRow
                        icon={Landmark}
                        title={
                            manualAccountsCount === 1
                                ? __('1 account you update by hand')
                                : __(':count accounts you update by hand', {
                                      count: manualAccountsCount,
                                  })
                        }
                        // The latam price has no bank connection to offer; it
                        // syncs brokers and exchanges instead.
                        description={
                            tier === 'latam'
                                ? __(
                                      'Connect your broker or exchange and new movements arrive on their own',
                                  )
                                : __(
                                      'Connect the bank and new movements arrive every day',
                                  )
                        }
                    />
                )}
            </StepList>
        </div>
    );
}

/**
 * Each gate argues the one thing that is true for it. The soft gate argues with
 * what the user already brought in: their movements are here, and Standard is
 * what sorts them and keeps the new ones coming.
 */
function gateDescription(
    gate: PaywallGate,
    stats: PaywallStats,
    locale: string,
): string {
    if (gate === 'soft') {
        return softDescription(stats.transactionsCount, locale);
    }

    const accounts =
        stats.connectionsCount === 1
            ? __('Your account stopped syncing')
            : __('Your :count accounts stopped syncing', {
                  count: stats.connectionsCount,
              });

    return __(
        ':accounts:on. Everything you imported before that is still here and still yours.',
        { accounts, on: endedOn(stats.endedAt, locale) },
    );
}

function softDescription(transactionsCount: number, locale: string): string {
    if (transactionsCount === 0) {
        return __(
            'Standard sorts your movements for you and brings the new ones in on their own.',
        );
    }

    if (transactionsCount === 1) {
        return __(
            '1 movement is in. Standard sorts it for you and brings the new ones in on their own.',
        );
    }

    return __(
        ':count movements are in. Standard sorts them for you and brings the new ones in on their own.',
        { count: transactionsCount.toLocaleString(locale) },
    );
}

/** " on 12 August", or nothing at all when there is no date to name. */
function endedOn(endedAt: string | null, locale: string): string {
    if (endedAt === null) {
        return '';
    }

    return __(' on :date', {
        date: new Date(endedAt).toLocaleDateString(locale, {
            day: 'numeric',
            month: 'long',
        }),
    });
}
