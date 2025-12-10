import { Button } from '@/components/ui/button';
import { ArrowRight, Bird, Sparkles } from 'lucide-react';

interface StepWelcomeProps {
    onContinue: () => void;
}

export function StepWelcome({ onContinue }: StepWelcomeProps) {
    return (
        <div className="flex animate-in flex-col items-center text-center duration-500 fade-in slide-in-from-bottom-4">
            <div className="mb-8 flex h-24 w-24 animate-in items-center justify-center rounded-full bg-gradient-to-br from-black to-zinc-700 shadow-lg duration-500 zoom-in">
                <Bird className="h-12 w-12 text-white" />
            </div>

            <h1 className="mb-4 text-4xl font-bold tracking-tight md:text-5xl">
                Welcome to Whisper Money
            </h1>

            <p className="mb-8 max-w-md text-lg text-muted-foreground">
                Take control of your finances with privacy-first money tracking.
                Let's set up your account in just a few minutes.
            </p>

            <div className="flex flex-col gap-4">
                <Button
                    size="lg"
                    onClick={onContinue}
                    className="group gap-2 px-8"
                >
                    Let's Get Started
                    <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
                </Button>

                <p className="text-sm text-muted-foreground">
                    This will take about 5 minutes
                </p>
            </div>
        </div>
    );
}
