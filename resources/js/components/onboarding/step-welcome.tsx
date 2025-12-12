import { StepButton } from '@/components/onboarding/step-button';
import { StepHeader } from '@/components/onboarding/step-header';
import { bankSyncService } from '@/services/bank-sync';
import { Bird } from 'lucide-react';
import { useEffect, useState } from 'react';

interface StepWelcomeProps {
    onContinue: () => void;
}

export function StepWelcome({ onContinue }: StepWelcomeProps) {
    const [isSyncing, setIsSyncing] = useState(true);

    useEffect(() => {
        const syncBanks = async () => {
            try {
                await bankSyncService.sync();
            } catch (error) {
                console.error('Failed to sync banks:', error);
            } finally {
                setIsSyncing(false);
            }
        };

        syncBanks();
    }, []);

    return (
        <div className="flex animate-in flex-col items-center text-center duration-500 fade-in slide-in-from-bottom-4">
            <StepHeader
                icon={Bird}
                iconContainerClassName="bg-gradient-to-br from-black to-zinc-700"
                title="Welcome to</br>Whisper Money"
                description="Take control of your finances with privacy-first money tracking. Let's set up your account in just a few minutes."
                large
            />

            <div className="flex w-full flex-col gap-4 sm:w-auto">
                <StepButton
                    text="Let's Get Started"
                    onClick={onContinue}
                    disabled={isSyncing}
                    loading={isSyncing}
                    loadingText="Preparing..."
                />

                <p className="text-sm text-muted-foreground">
                    This will take less than 5 minutes
                </p>
            </div>
        </div>
    );
}
