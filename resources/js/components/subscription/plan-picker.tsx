import {
    StepBadge,
    StepCheck,
    StepList,
    StepRow,
} from '@/components/onboarding/step-list';
import { useLocale } from '@/hooks/use-locale';
import { Plan } from '@/types/pricing';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';

/**
 * The plan chooser of the whole paid path — the paywall, the upgrade dialog and
 * settings → billing all pick from these same rows, so there is one place where
 * a plan's price, saving and selected state are rendered.
 *
 * `size` is the row density: `default` is the onboarding's, `compact` is the
 * app's own, for the surfaces that sit inside the normal app chrome.
 */
export function PlanPicker({
    plans,
    currency,
    selectedPlan,
    onSelect,
    size = 'default',
}: {
    plans: Record<string, Plan>;
    currency: string;
    selectedPlan: string;
    onSelect: (key: string) => void;
    size?: 'default' | 'compact';
}) {
    return (
        <StepList>
            {Object.entries(plans).map(([key, plan]) => (
                <PlanRow
                    key={key}
                    plan={plan}
                    currency={currency}
                    size={size}
                    isSelected={key === selectedPlan}
                    onSelect={() => onSelect(key)}
                />
            ))}
        </StepList>
    );
}

/**
 * One plan. Selection is a check in the trailing slot: no ring, no fill, no
 * colour, which is what lets these screens carry no `dark:` variants at all.
 * The check is the only signal, so it carries a text alternative — without the
 * old emerald ring there is nothing else for a screen reader to read.
 */
function PlanRow({
    plan,
    isSelected,
    onSelect,
    currency,
    size,
}: {
    plan: Plan;
    isSelected: boolean;
    onSelect: () => void;
    currency: string;
    size: 'default' | 'compact';
}) {
    const locale = useLocale();
    const savingsPercent = planSavingsPercent(plan);

    return (
        <StepRow
            size={size}
            title={planTitle(plan)}
            badge={
                savingsPercent ? (
                    <StepBadge>
                        {__('Save :percent%', { percent: savingsPercent })}
                    </StepBadge>
                ) : undefined
            }
            description={planPrice(plan, currency, locale)}
            trailing={
                isSelected ? (
                    <>
                        <StepCheck />
                        <span className="sr-only">{__('Selected')}</span>
                    </>
                ) : undefined
            }
            onClick={onSelect}
        />
    );
}

function planTitle(plan: Plan): string {
    if (plan.billing_period === 'year') {
        return __('Yearly');
    }

    if (plan.billing_period === 'month') {
        return __('Monthly');
    }

    return __('One-time');
}

/**
 * How much the yearly plan saves against paying monthly. The monthly price is
 * in the row directly below, so the percentage has its referent on screen.
 */
function planSavingsPercent(plan: Plan): number | null {
    if (!plan.original_price || plan.billing_period !== 'year') {
        return null;
    }

    const percent = Math.round(
        ((plan.original_price - plan.price) / plan.original_price) * 100,
    );

    return percent > 0 ? percent : null;
}

function planPrice(plan: Plan, currency: string, locale: string): string {
    const total = formatCurrency(plan.price * 100, currency, locale);

    if (plan.billing_period === 'year') {
        return __(':monthly/month, billed as :total a year', {
            monthly: formatCurrency((plan.price / 12) * 100, currency, locale),
            total,
        });
    }

    if (plan.billing_period === 'month') {
        return __(':total a month', { total });
    }

    return __(':total once', { total });
}

/**
 * The commitment line that sits directly above a checkout button. It carries
 * the amount as well as the date, so the button is self-contained: the selected
 * row can be scrolled off, or behind a sticky footer, and the user can still
 * read what they are about to be charged and when.
 */
export function planTerms(
    plan: Plan | undefined,
    currency: string,
    locale: string,
): string | null {
    if (!plan) {
        return null;
    }

    const total = formatCurrency(plan.price * 100, currency, locale);

    if (plan.trial_days <= 0) {
        // A plan with no billing period is bought once, so there is nothing to
        // cancel and nothing to renew.
        return plan.billing_period === null
            ? __(':total charged today. Yours for good.', { total })
            : __(':total charged today. Cancel any time.', { total });
    }

    if (plan.billing_period === 'year') {
        return __(
            'Free for :days days, then :total a year. Cancel before then and you are not charged.',
            { days: plan.trial_days, total },
        );
    }

    return __(
        'Free for :days days, then :total a month. Cancel before then and you are not charged.',
        { days: plan.trial_days, total },
    );
}
