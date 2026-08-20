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
 * `size` is the only thing that varies between callers: the paid path runs at
 * onboarding density, settings → billing at the app's own.
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

    const savingsPercent =
        plan.original_price && plan.billing_period === 'year'
            ? Math.round(
                  ((plan.original_price - plan.price) / plan.original_price) *
                      100,
              )
            : null;

    const total = formatCurrency(plan.price * 100, currency, locale);

    return (
        <StepRow
            size={size}
            title={planTitle(plan)}
            badge={
                savingsPercent && savingsPercent > 0 ? (
                    <StepBadge>
                        {__('Save :percent%', { percent: savingsPercent })}
                    </StepBadge>
                ) : undefined
            }
            description={planPrice(plan, total, currency, locale)}
            trailing={isSelected ? <StepCheck /> : undefined}
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

function planPrice(
    plan: Plan,
    total: string,
    currency: string,
    locale: string,
): string {
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
 * The commitment line that sits directly above a checkout button. It says when
 * money leaves, not what it costs — the price is in the selected row right
 * above it, so repeating it there would be the same fact twice.
 */
export function planTerms(plan: Plan | undefined): string {
    if (!plan) {
        return '';
    }

    if (plan.trial_days > 0) {
        return __(
            'Free for :days days. Cancel any time before then and you are not charged.',
            { days: plan.trial_days },
        );
    }

    return __('Billed today. Cancel any time.');
}
