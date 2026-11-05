import { StepButton } from '@/components/onboarding/step-button';
import { StepNote } from '@/components/onboarding/step-screen';
import { PlanPicker, planTerms } from '@/components/subscription/plan-picker';
import { checkoutHref } from '@/components/subscription/subscription-offer';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { captureEvent } from '@/lib/posthog';
import { type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * The upsell entry point a checkout starts from. Mirrors the PHP
 * App\Enums\UpsellSource so revenue can be attributed per point.
 */
export type UpsellSource = 'ai_categorization' | 'connections' | 'accounts';

/**
 * A contextual "this is a paid feature" dialog with a plan picker that starts
 * Stripe checkout. Reused across upsell points (AI categorization, bank
 * connections, connected accounts); each passes its own copy and `source`.
 */
export function UpgradeDialog({
    open,
    onOpenChange,
    title,
    description,
    source,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    source: UpsellSource;
}) {
    const { pricing, locale } = usePage<SharedData>().props;
    const [preselectedPlan] = useState(pricing.defaultPlan);
    const [selectedPlan, setSelectedPlan] = useState(preselectedPlan);
    const terms = planTerms(
        pricing.plans[selectedPlan],
        pricing.currency,
        locale,
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <PlanPicker
                    plans={pricing.plans}
                    currency={pricing.currency}
                    selectedPlan={selectedPlan}
                    onSelect={setSelectedPlan}
                />

                <div className="flex flex-col gap-2.5">
                    {terms && <StepNote emphasis>{terms}</StepNote>}

                    <StepButton
                        text={__('Start a plan')}
                        href={checkoutHref(
                            selectedPlan,
                            preselectedPlan,
                            source,
                        )}
                        onClick={() =>
                            captureEvent('upgrade_checkout_started', {
                                source,
                                plan: selectedPlan,
                                preselected_plan: preselectedPlan,
                            })
                        }
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
