import { complete } from '@/actions/App/Http/Controllers/OnboardingController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { router } from '@inertiajs/react';
import { ArrowRight, PartyPopper, Sparkles } from 'lucide-react';
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

                {showConfetti && (
                    <div className="absolute inset-0 animate-in duration-500 fade-in">
                        {[...Array(8)].map((_, i) => (
                            <Sparkles
                                key={i}
                                className={`absolute h-4 w-4 animate-ping ${
                                    i % 3 === 0
                                        ? 'text-amber-400'
                                        : i % 3 === 1
                                          ? 'text-pink-400'
                                          : 'text-blue-400'
                                }`}
                                style={{
                                    top: `${50 + Math.sin((i * Math.PI * 2) / 8) * 60}%`,
                                    left: `${50 + Math.cos((i * Math.PI * 2) / 8) * 60}%`,
                                    animationDelay: `${i * 100}ms`,
                                    animationDuration: '1s',
                                }}
                            />
                        ))}
                    </div>
                )}
            </div>

            <h1 className="mb-4 text-4xl font-bold tracking-tight md:text-5xl">
                You're All Set!
            </h1>

            <p className="mb-8 max-w-md text-lg text-muted-foreground">
                Your accounts are ready and your data is securely encrypted.
                Welcome to Whisper Money!
            </p>

            <div className="mb-8 flex w-full max-w-md flex-col gap-4">
                <div className="flex items-center justify-center gap-4 rounded-xl border bg-card p-4">
                    <div className="mb-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        ✓
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Encryption Set
                    </p>
                </div>
                <div className="flex items-center justify-center gap-4 rounded-xl border bg-card p-4">
                    <div className="mb-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        ✓
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Accounts Created
                    </p>
                </div>
                <div className="flex items-center justify-center gap-4 rounded-xl border bg-card p-4">
                    <div className="mb-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        ✓
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Data Imported
                    </p>
                </div>
            </div>

            <Button
                size="lg"
                onClick={handleComplete}
                disabled={isRedirecting}
                className="group gap-2 px-8"
            >
                {isRedirecting ? (
                    <>
                        <Spinner />
                        Redirecting...
                    </>
                ) : (
                    <>
                        Go to Dashboard
                        <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
                    </>
                )}
            </Button>
        </div>
    );
}
