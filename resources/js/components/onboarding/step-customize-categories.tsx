import { StepButton } from '@/components/onboarding/step-button';
import { StepHeader } from '@/components/onboarding/step-header';
import { Button } from '@/components/ui/button';
import { __ } from '@/utils/i18n';
import { Check, Settings, SkipForward } from 'lucide-react';

interface StepCustomizeCategoriesProps {
    onContinue: () => void;
    onSkip: () => void;
}

export function StepCustomizeCategories({
    onContinue,
    onSkip,
}: StepCustomizeCategoriesProps) {
    return (
        <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
            <StepHeader
                icon={Settings}
                iconContainerClassName="bg-gradient-to-br from-pink-400 to-rose-500"
                title={__('Customize Your Categories')}
                description={__(
                    "We've created a comprehensive set of categories for you. You can customize them now or adjust them later in settings.",
                )}
            />

            <div className="mb-8 w-full max-w-md rounded-xl border bg-card p-6">
                <h3 className="mb-4 font-semibold">
                    {__('Your Categories Include:')}
                </h3>
                <div className="space-y-2">
                    {[
                        __('Food & Dining (Groceries, Restaurants, Delivery)'),
                        __('Housing (Rent, Utilities, Maintenance)'),
                        __('Transportation (Fuel, Public Transit, Parking)'),
                        __('Shopping (Clothing, Electronics, Gifts)'),
                        __('Entertainment (Movies, Sports, Hobbies)'),
                        __('Health & Wellness (Medical, Pharmacy, Fitness)'),
                        __('Income (Salary, Freelance, Investments)'),
                        __('Transfers (Between accounts, Savings)'),
                    ].map((category) => (
                        <div
                            key={category}
                            className="flex items-center gap-2 text-sm"
                        >
                            <Check className="h-4 w-4 shrink-0 text-emerald-500" />
                            <span>{category}</span>
                        </div>
                    ))}
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <span className="ml-6">{__('...and 40+ more')}</span>
                    </div>
                </div>
            </div>

            <div className="flex flex-col gap-3 sm:flex-row">
                <Button
                    variant="outline"
                    size="lg"
                    onClick={onSkip}
                    className="group gap-2"
                >
                    <SkipForward className="h-4 w-4" />
                    {__('Use Defaults')}
                </Button>
                <StepButton text={__('Continue')} onClick={onContinue} />
            </div>

            <p className="mt-4 text-center text-xs text-muted-foreground">
                {__(
                    'You can always customize categories later in Settings \u2192\n                Categories',
                )}
            </p>
        </div>
    );
}
