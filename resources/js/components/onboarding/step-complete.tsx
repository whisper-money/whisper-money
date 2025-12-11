import { complete } from '@/actions/App/Http/Controllers/OnboardingController';
import { StepButton } from '@/components/onboarding/step-button';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { router } from '@inertiajs/react';
import { PartyPopper, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';

export function StepComplete() {
    const [isRedirecting, setIsRedirecting] = useState(false);
    const [showConfetti, setShowConfetti] = useState(false);

    useEffect(() => {
        const timer = setTimeout(() => {
            setShowConfetti(true);
        }, 300);
        return () => clearTimeout(timer);
    }, []);

    const handleComplete = () => {
        setIsRedirecting(true);

        router.post(
            complete.url(),
            {},
            {
                onSuccess: () => {
                    router.visit(dashboard().url);
                },
                onError: () => {
                    setIsRedirecting(false);
                },
            },
        );
    };

    return (
        <div className="flex animate-in flex-col items-center text-center duration-500 fade-in slide-in-from-bottom-4">
            <div className="relative mb-8">
                <div className="flex h-24 w-24 animate-in items-center justify-center rounded-full bg-gradient-to-br from-emerald-400 to-teal-500 shadow-lg duration-700 spin-in-180 zoom-in">
                    <PartyPopper className="h-12 w-12 text-white" />
                </div>
            </div>

            <h1 className="mb-2 text-3xl font-bold tracking-tight sm:text-4xl md:text-5xl">
                You're All Set!
            </h1>

            <p className="mb-8 text-balance max-w-lg text-lg text-muted-foreground">
                Your accounts are ready and your data is securely encrypted.
                Welcome to Whisper Money!
            </p>

            <div className="mb-12justify-center flex w-full max-w-md flex-col gap-4">
                <div className="flex items-center justify-center gap-2 rounded-xl border bg-card p-2">
                    <div className="ml-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        ✓
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Encryption Set
                    </p>
                </div>
                <div className="flex items-center justify-center gap-2 rounded-xl border bg-card p-2">
                    <div className="ml-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        ✓
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Accounts Created
                    </p>
                </div>
                <div className="flex items-center justify-center gap-2 rounded-xl border bg-card p-2">
                    <div className="ml-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        ✓
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Data Imported
                    </p>
                </div>
            </div>

            <StepButton
                text="Go to Dashboard"
                onClick={handleComplete}
                loading={isRedirecting}
                loadingText="Redirecting..."
            />
        </div>
    );
}
