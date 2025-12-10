import { Button } from '@/components/ui/button';
import { ArrowRight, Check, Settings, SkipForward } from 'lucide-react';

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
            <div className="mb-8 flex h-20 w-20 animate-in items-center justify-center rounded-full bg-gradient-to-br from-pink-400 to-rose-500 shadow-lg duration-500 zoom-in">
                <Settings className="h-10 w-10 text-white" />
            </div>

            <h1 className="mb-4 text-center text-3xl font-bold tracking-tight">
                Customize Your Categories
            </h1>

            <p className="mb-8 max-w-md text-center text-muted-foreground">
                We've created a comprehensive set of categories for you. You can
                customize them now or adjust them later in settings.
            </p>

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
                <Button
                    size="lg"
                    onClick={onContinue}
                    className="group gap-2 px-8"
                >
                    Continue
                    <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
                </Button>
            </div>

            <p className="mt-4 text-center text-xs text-muted-foreground">
                You can always customize categories later in Settings →
                Categories
            </p>
        </div>
    );
}
