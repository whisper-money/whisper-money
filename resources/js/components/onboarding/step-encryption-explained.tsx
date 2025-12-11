import { StepButton } from '@/components/onboarding/step-button';
import { StepHeader } from '@/components/onboarding/step-header';
import { Eye, EyeOff, Lock, Server, Shield, User } from 'lucide-react';

interface StepEncryptionExplainedProps {
    onContinue: () => void;
}

export function StepEncryptionExplained({
    onContinue,
}: StepEncryptionExplainedProps) {
    return (
        <div className="flex animate-in flex-col items-center text-center duration-500 fade-in slide-in-from-bottom-4">
            <StepHeader
                icon={Shield}
                iconContainerClassName="bg-gradient-to-br from-blue-500 to-indigo-600"
                title="Your Data, Your Privacy"
                description="Whisper Money uses end-to-end encryption to protect your financial data. Here's how it works:"
            />

            <div className="mb-5 grid w-full max-w-xl gap-4 sm:mb-4">
                <Item
                    title="Create a Password"
                    description="Only you know this password. It never leaves your device."
                    icon={
                        <User className="size-4 text-emerald-600 dark:text-emerald-400" />
                    }
                />

                <Item
                    title="Data is Encrypted"
                    description="Your data is encrypted before it leaves your browser."
                    icon={
                        <Lock className="size-4 text-blue-600 dark:text-blue-400" />
                    }
                />

                <Item
                    title="We Can't Read It"
                    description="Even we can't access your data. It's truly private."
                    icon={
                        <Server className="size-4 text-violet-600 dark:text-violet-400" />
                    }
                />
            </div>

            <div className="mb-8 flex w-full max-w-xl flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-muted-foreground/20 p-4">
                <div className="flex items-center gap-2">
                    <Eye className="h-5 w-5 text-muted-foreground" />
                    <span className="text-sm font-medium">You see:</span>
                    <span className="font-mono text-emerald-600 dark:text-emerald-400">
                        STARBUCKS@TEKKA PLC
                    </span>
                </div>
                <div className="flex items-center gap-2">
                    <EyeOff className="h-5 w-5 text-muted-foreground" />
                    <span className="text-sm font-medium">We see:</span>
                    <span className="font-mono text-muted-foreground">
                        $KO!F6LMHU1W%TAEQFZMD9
                    </span>
                </div>
            </div>

            <StepButton
                text="I Understand, Continue"
                onClick={onContinue}
                data-testid="encryption-continue-button"
            />
        </div>
    );
}

function Item({
    title,
    description,
    icon,
}: {
    title: string;
    description: string;
    icon: React.ReactNode;
}) {
    return (
        <div className="flex w-full flex-col items-start gap-2 rounded-xl border bg-card p-3 sm:p-5">
            <div className="flex flex-row items-center gap-2">
                {icon}
                <h3 className="font-semibold">{title}</h3>
            </div>
            <div className="text-left sm:text-center">
                <p className="text-sm text-pretty text-muted-foreground">
                    {description}
                </p>
            </div>
        </div>
    );
}
