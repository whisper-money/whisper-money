import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { Link } from '@inertiajs/react';
import { type PropsWithChildren, useEffect, useState } from 'react';

interface OnboardingLayoutProps {
    currentStep: number;
    totalSteps: number;
    stepKey: string;
}

export default function OnboardingLayout({
    children,
    currentStep,
    totalSteps,
    stepKey,
}: PropsWithChildren<OnboardingLayoutProps>) {
    const [isVisible, setIsVisible] = useState(false);

    useEffect(() => {
        setIsVisible(false);
        const timer = setTimeout(() => setIsVisible(true), 50);
        return () => clearTimeout(timer);
    }, [stepKey]);

    return (
        <div className="flex min-h-svh flex-col bg-background">
            <header className="flex items-center justify-between p-4 md:p-6">
                <Link
                    href={home()}
                    className="flex items-center gap-2 font-medium"
                >
                    <AppLogoIcon
                        className={cn([
                            'size-8 fill-current text-[var(--foreground)] opacity-100 transition-opacity duration-200 dark:text-white',
                            { 'opacity-0': currentStep === 0 },
                        ])}
                    />
                </Link>

                <div className="flex items-center gap-2">
                    {Array.from({ length: totalSteps }).map((_, index) => (
                        <div
                            key={index}
                            className={cn(
                                'h-2 w-2 rounded-full transition-all duration-300',
                                index < currentStep
                                    ? 'bg-primary'
                                    : index === currentStep
                                        ? 'scale-125 bg-primary'
                                        : 'bg-muted',
                            )}
                        />
                    ))}
                </div>

                <div></div>
            </header>

            <main className="flex flex-1 flex-col items-center justify-start sm:justify-center px-4 pt-12 md:px-6">
                <div
                    key={stepKey}
                    className={cn(
                        'w-full max-w-2xl transition-all duration-300 ease-out',
                        isVisible
                            ? 'translate-y-0 opacity-100'
                            : 'translate-y-5 opacity-0',
                    )}
                >
                    {children}
                </div>
            </main>
        </div>
    );
}
