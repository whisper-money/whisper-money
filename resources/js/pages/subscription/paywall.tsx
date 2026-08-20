import { StepButton } from '@/components/onboarding/step-button';
import {
    StepChevron,
    StepList,
    StepRow,
} from '@/components/onboarding/step-list';
import { StepNote, StepScreen } from '@/components/onboarding/step-screen';
import { PlanPicker, planTerms } from '@/components/subscription/plan-picker';
import { SupportDialog } from '@/components/support-dialog';
import SubscriptionLayout from '@/layouts/subscription-layout';
import { dashboard } from '@/routes';
import { index as connectionsIndex } from '@/routes/settings/connections';
import { checkout } from '@/routes/subscribe';
import { type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import { Landmark, LifeBuoy, Sparkles, WalletMinimal } from 'lucide-react';
import { useState } from 'react';

interface PaywallStats {
    accountsCount: number;
    transactionsCount: number;
    categoriesCount: number;
}

interface PaywallPageProps extends SharedData {
    stats: PaywallStats;
    canUseFreePlan: boolean;
    canManageConnectionsForFreePlan: boolean;
}

export default function Paywall() {
    const {
        auth,
        locale,
        pricing,
        stats,
        canUseFreePlan,
        canManageConnectionsForFreePlan,
    } = usePage<PaywallPageProps>().props;

    const [selectedPlan, setSelectedPlan] = useState(pricing.defaultPlan);
    const [supportOpen, setSupportOpen] = useState(false);

    if (Object.keys(pricing.plans).length === 0) {
        return null;
    }

    // Someone who already paid once does not need the product explained to
    // them — they need the two doors: start again, or disconnect and go free.
    const isFormerSubscriber = canManageConnectionsForFreePlan;

    return (
        <SubscriptionLayout>
            <Head title={__('Choose your plan')} />

            <StepScreen
                title={
                    isFormerSubscriber
                        ? __('Your plan has ended')
                        : __('Choose your plan')
                }
                description={description({
                    canUseFreePlan,
                    isFormerSubscriber,
                    stats,
                    locale,
                })}
                footer={
                    <>
                        {!isFormerSubscriber && (
                            <StepNote>
                                {__(
                                    '4,000+ people track their money here. We never sell your data.',
                                )}
                            </StepNote>
                        )}

                        <StepNote emphasis>
                            {planTerms(pricing.plans[selectedPlan])}
                        </StepNote>

                        <StepButton
                            text={__('Continue')}
                            href={checkout.url({
                                query: { plan: selectedPlan },
                            })}
                        />

                        {/* The way out is here from the first paint. A timed
                            reveal hides the only exit a hard-gated user has,
                            and on the soft gate it hides a choice they are
                            entitled to make — the filled-versus-muted
                            hierarchy already says which one is the offer. */}
                        {canUseFreePlan ? (
                            <StepButton
                                text={__('Continue with the free plan')}
                                variant="ghost"
                                onClick={() => router.visit(dashboard().url)}
                            />
                        ) : (
                            <StepButton
                                text={__('Need help?')}
                                variant="ghost"
                                icon={LifeBuoy}
                                onClick={() => setSupportOpen(true)}
                            />
                        )}
                    </>
                }
            >
                {!isFormerSubscriber && <Features />}

                <PlanPicker
                    plans={pricing.plans}
                    currency={pricing.currency}
                    selectedPlan={selectedPlan}
                    onSelect={setSelectedPlan}
                />

                {isFormerSubscriber && (
                    <div>
                        <div className="pt-4 pb-2.5 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                            {__('Or go free')}
                        </div>
                        <StepList>
                            <StepRow
                                icon={Landmark}
                                title={__('Disconnect your banks')}
                                description={__(
                                    'In Settings, under Connections.',
                                )}
                                meta={__(
                                    'Your accounts stop updating. Every transaction already imported stays.',
                                )}
                                trailing={<StepChevron />}
                                onClick={() =>
                                    router.visit(connectionsIndex().url)
                                }
                            />
                        </StepList>
                    </div>
                )}
            </StepScreen>

            <SupportDialog
                open={supportOpen}
                onOpenChange={setSupportOpen}
                user={auth.user}
            />
        </SubscriptionLayout>
    );
}

/** What a paid plan adds, in the list vocabulary the rest of the flow uses. */
function Features() {
    return (
        <StepList>
            <StepRow
                icon={Landmark}
                title={__('Connected banks')}
                description={__('Transactions and balances sync themselves.')}
            />
            <StepRow
                icon={Sparkles}
                title={__('AI suggestions')}
                description={__(
                    'Rules learned from how you already categorize.',
                )}
            />
            <StepRow
                icon={WalletMinimal}
                title={__('No limits')}
                description={__('Accounts, transactions and categories.')}
            />
        </StepList>
    );
}

/**
 * Each gate argues the one thing that is true for it. The hard gate names what
 * the user already has here, because that is what is being withheld; the soft
 * gate does not, because nothing is.
 */
function description({
    canUseFreePlan,
    isFormerSubscriber,
    stats,
    locale,
}: {
    canUseFreePlan: boolean;
    isFormerSubscriber: boolean;
    stats: PaywallStats;
    locale: string;
}): string {
    if (isFormerSubscriber) {
        return __(
            'Your connected banks stopped syncing. Start a plan to turn them back on, or disconnect them and keep going for free.',
        );
    }

    if (canUseFreePlan) {
        return __(
            'Syncing and AI suggestions need a paid plan. Everything else in Whisper Money stays free.',
        );
    }

    const gate = __('Bank syncing and AI suggestions need a paid plan.');
    const snapshot = snapshotSentence(stats, locale);

    return snapshot ? `${gate} ${snapshot}` : gate;
}

/**
 * The one personal, honest argument on the page: the data the user has already
 * put in. Both counts have to be there for the sentence to read, so it is
 * dropped whole rather than degrading into "0 transactions".
 */
function snapshotSentence(stats: PaywallStats, locale: string): string | null {
    if (stats.accountsCount === 0 || stats.transactionsCount === 0) {
        return null;
    }

    const accounts =
        stats.accountsCount === 1
            ? __('1 account')
            : __(':count accounts', {
                  count: stats.accountsCount.toLocaleString(locale),
              });

    const transactions =
        stats.transactionsCount === 1
            ? __('1 transaction')
            : __(':count transactions', {
                  count: stats.transactionsCount.toLocaleString(locale),
              });

    return __('Your :accounts and :transactions are already here.', {
        accounts,
        transactions,
    });
}
