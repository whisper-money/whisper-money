import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { ArrowLeft } from 'lucide-react';
import { type PropsWithChildren, useEffect, useState } from 'react';

interface OnboardingLayoutProps {
    currentStep: number;
    totalSteps: number;
    stepKey: string;
    onBack?: () => void;
}

export default function OnboardingLayout({
    children,
    currentStep,
    totalSteps,
    stepKey,
    onBack,
}: PropsWithChildren<OnboardingLayoutProps>) {
    const [isVisible, setIsVisible] = useState(false);

    useEffect(() => {
        setIsVisible(false);
        const timer = setTimeout(() => setIsVisible(true), 50);
        return () => clearTimeout(timer);
    }, [stepKey]);

    const progress = Math.round(((currentStep + 1) / totalSteps) * 100);

    return (
        <div className="flex min-h-svh flex-col bg-background">
            {/* Progress reads as a hairline at the very top edge: present when
                looked for, invisible otherwise. */}
            <div
                className="h-0.5 shrink-0 bg-border"
                role="progressbar"
                aria-valuenow={currentStep + 1}
                aria-valuemin={1}
                aria-valuemax={totalSteps}
                aria-label={__('Onboarding progress')}
            >
                <div
                    className="h-full bg-foreground transition-[width] duration-500 ease-out"
                    style={{ width: `${progress}%` }}
                />
            </div>

            <header className="pt-safe flex min-h-14 shrink-0 items-center justify-between gap-3 px-5 md:min-h-18 md:px-7">
                {onBack ? (
                    <button
                        type="button"
                        onClick={onBack}
                        aria-label={__('Back')}
                        className="-ml-2 cursor-pointer rounded-md p-2 text-muted-foreground transition-colors outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <ArrowLeft className="size-6" />
                    </button>
                ) : (
                    <AppLogoIcon className="size-6 fill-current text-foreground" />
                )}
            </header>

            <div
                key={stepKey}
                className={cn(
                    'flex flex-1 flex-col transition-all duration-300 ease-out',
                    isVisible
                        ? 'translate-y-0 opacity-100'
                        : 'translate-y-3 opacity-0',
                )}
            >
                {children}
            </div>
        </div>
    );
}
