import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import { StepScreen } from '@/components/onboarding/step-screen';
import SubscriptionLayout from '@/layouts/subscription-layout';
import { dashboard } from '@/routes';
import { __ } from '@/utils/i18n';
import { Head, router } from '@inertiajs/react';
import { Check } from 'lucide-react';

/**
 * Where Stripe drops the user after a successful checkout. There is nothing to
 * wait for here — the subscription exists by the time this renders — so the
 * screen states what is on and gets out of the way.
 */
export default function Success() {
    return (
        <SubscriptionLayout>
            <Head title={__('Your plan is active')} />

            <StepScreen
                title={__("You're on the Standard plan")}
                description={__(
                    'Everything is on. Your banks start syncing on the next run.',
                )}
                footer={
                    <StepButton
                        text={__('Go to Dashboard')}
                        onClick={() => router.visit(dashboard().url)}
                    />
                }
            >
                <StepList>
                    <StepRow icon={Check} title={__('Connected banks')} />
                    <StepRow icon={Check} title={__('AI suggestions')} />
                    <StepRow icon={Check} title={__('No limits')} />
                </StepList>
            </StepScreen>
        </SubscriptionLayout>
    );
}
