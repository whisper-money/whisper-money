import { complete } from '@/actions/App/Http/Controllers/OnboardingController';
import { StepButton } from '@/components/onboarding/step-button';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { PartyPopper } from 'lucide-react';
import { useState } from 'react';

export function StepComplete() {
    const [isRedirecting, setIsRedirecting] = useState(false);

    const handleComplete = () => {
        setIsRedirecting(true);

        router.post(
            complete.url(),
            {},
            {
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
                {__("You're All Set!")}
            </h1>

            <p className="mb-8 max-w-lg text-lg text-balance text-muted-foreground">
                {__(
                    'Your accounts are ready and your data is securely encrypted. Welcome to Whisper Money!',
                )}
            </p>

            <div className="mb-12 flex w-full max-w-md flex-col justify-center gap-4">
                <div className="flex items-center justify-center gap-2 rounded-xl border bg-card p-2">
                    <div className="ml-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        ✓
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {__('Encryption Set')}
                    </p>
                </div>
                <div className="flex items-center justify-center gap-2 rounded-xl border bg-card p-2">
                    <div className="ml-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        ✓
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {__('Accounts Created')}
                    </p>
                </div>
                <div className="flex items-center justify-center gap-2 rounded-xl border bg-card p-2">
                    <div className="ml-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        ✓
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {__('Data Imported')}
                    </p>
                </div>
            </div>

            <StepButton
                text={__('Go to Dashboard')}
                onClick={handleComplete}
                loading={isRedirecting}
                loadingText={__('Redirecting...')}
            />
        </div>
    );
}
