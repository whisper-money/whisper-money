import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import { StepScreen } from '@/components/onboarding/step-screen';
import { PlanPicker } from '@/components/subscription/plan-picker';
import {
    SubscriptionOffer,
    useOffer,
} from '@/components/subscription/subscription-offer';
import { SupportDialog } from '@/components/support-dialog';
import { useLocale } from '@/hooks/use-locale';
import SubscriptionLayout from '@/layouts/subscription-layout';
import { captureEvent } from '@/lib/posthog';
import { dashboard } from '@/routes';
import { confirm as freePlanConfirm } from '@/routes/subscribe/free-plan';
import { type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import { Check, CircleAlert, LifeBuoy, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';

interface PaywallStats {
    accountsCount: number;
    transactionsCount: number;
    categoriesCount: number;
    rulesCount: number;
    connectionsCount: number;
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

/**
 * Which of the three screens the user gets.
 *
 * `soft` is the only one a signup reaching this today can land on: a bank and
 * the AI both now require a plan before they can be switched on, so nobody
 * finishes onboarding owing us anything. The other two are the users who got
 * into that state under the old rules, and the users who paid and stopped.
 */
type PaywallGate = 'former-subscriber' | 'soft' | 'hard';

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
    const { selectedPlan, setSelectedPlan, hasPlans, button } = useOffer();

    const gate: PaywallGate = canManageConnectionsForFreePlan
        ? 'former-subscriber'
        : canUseFreePlan
          ? 'soft'
          : 'hard';

    // The gates that have something to disconnect, once the delay after
    // onboarding is up. Derived once so the button and the screen it opens
    // cannot drift apart.
    const freeDoorOpen = gate !== 'soft' && canEscapeToFreePlan;

    // This is the highest-leverage screen in the product and it carried no
    // instrumentation at all, so moving the escape hatch to first paint would
    // have shipped unmeasurable. `paywall_seen_at` is stamped on view, so it
    // cannot stand in for the choice.
    useEffect(() => {
        captureEvent('paywall_viewed', { gate });
    }, [gate]);

    if (!hasPlans) {
        return null;
    }

    const isFormer = gate !== 'soft';

    const title = isFormer
        ? __('Your plan ended')
        : __('One thing left to decide');

    const continueFree = () => {
        captureEvent('paywall_free_plan_chosen');
        router.visit(dashboard().url);
    };

    const openFreePlanConfirmation = () => {
        captureEvent('paywall_free_plan_confirm_opened', { gate });
        router.visit(freePlanConfirm().url);
    };

    return (
        <SubscriptionLayout>
            <Head title={title} />

            <StepScreen
                title={title}
                description={gateDescription(gate, stats, locale)}
                // Not pinned: a tall sticky footer painted over both plan rows
                // on any window under ~900px tall.
                footer={
                    <>
                        {button}

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
                            <StepButton
                                text={__('Carry on free')}
                                variant="ghost"
                                onClick={continueFree}
                                data-testid="carry-on-free"
                            />
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
                            plans={pricing.plans}
                            currency={pricing.currency}
                            selectedPlan={selectedPlan}
                            onSelect={setSelectedPlan}
                        />
                    </>
                ) : (
                    <>
                        <SubscriptionOffer
                            selectedPlan={selectedPlan}
                            onSelect={setSelectedPlan}
                        />
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
                    </>
                )}
            </StepScreen>

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

/**
 * Each gate argues the one thing that is true for it. The soft gate is the only
 * one that can say nothing is locked, and it says so first: a user who reached
 * the end of onboarding without a bank and without the AI is being offered
 * something, not cut off.
 */
function gateDescription(
    gate: PaywallGate,
    stats: PaywallStats,
    locale: string,
): string {
    if (gate === 'soft') {
        return __(
            'Nothing is locked and nothing is about to be cut off — you got here without a bank or the AI. This is just the offer.',
        );
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
