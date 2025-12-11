import { StepButton } from '@/components/onboarding/step-button';
import { StepHeader } from '@/components/onboarding/step-header';
import { Button } from '@/components/ui/button';
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
                title="Customize Your Categories"
                description="We've created a comprehensive set of categories for you. You can customize them now or adjust them later in settings."
            />

            <div className="mb-8 w-full max-w-md rounded-xl border bg-card p-6">
                <h3 className="mb-4 font-semibold">Your Categories Include:</h3>
                <div className="space-y-2">
                    {[
                        'Food & Dining (Groceries, Restaurants, Delivery)',
                        'Housing (Rent, Utilities, Maintenance)',
                        'Transportation (Fuel, Public Transit, Parking)',
                        'Shopping (Clothing, Electronics, Gifts)',
                        'Entertainment (Movies, Sports, Hobbies)',
                        'Health & Wellness (Medical, Pharmacy, Fitness)',
                        'Income (Salary, Freelance, Investments)',
                        'Transfers (Between accounts, Savings)',
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
                        <span className="ml-6">...and 40+ more</span>
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
                    Use Defaults
                </Button>
                <StepButton text="Continue" onClick={onContinue} />
            </div>

            <p className="mt-4 text-center text-xs text-muted-foreground">
                You can always customize categories later in Settings →
                Categories
            </p>
        </div>
    );
}
