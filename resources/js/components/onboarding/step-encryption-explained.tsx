import { Button } from '@/components/ui/button';
import {
    ArrowRight,
    Eye,
    EyeOff,
    Lock,
    Server,
    Shield,
    User,
} from 'lucide-react';

interface StepEncryptionExplainedProps {
    onContinue: () => void;
}

export function StepEncryptionExplained({
    onContinue,
}: StepEncryptionExplainedProps) {
    return (
        <div className="flex animate-in flex-col items-center text-center duration-500 fade-in slide-in-from-bottom-4">
            <div className="mb-8 flex h-24 w-24 animate-in items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 shadow-lg duration-500 zoom-in">
                <Shield className="h-12 w-12 text-white" />
            </div>

            <h1 className="mb-4 text-3xl font-bold tracking-tight md:text-4xl">
                Your Data, Your Privacy
            </h1>

            <p className="mb-8 max-w-lg text-lg text-muted-foreground">
                Whisper Money uses end-to-end encryption to protect your
                financial data. Here's how it works:
            </p>

            <div className="mb-8 grid w-full max-w-xl gap-4 md:grid-cols-3">
                <div className="flex flex-col items-center gap-3 rounded-xl border bg-card p-6">
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                        <User className="h-6 w-6 text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <h3 className="font-semibold">You Create a Password</h3>
                    <p className="text-sm text-muted-foreground">
                        Only you know this password. It never leaves your
                        device.
                    </p>
                </div>

                <div className="flex flex-col items-center gap-3 rounded-xl border bg-card p-6">
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30">
                        <Lock className="h-6 w-6 text-blue-600 dark:text-blue-400" />
                    </div>
                    <h3 className="font-semibold">Data is Encrypted</h3>
                    <p className="text-sm text-muted-foreground">
                        Your financial data is encrypted before it leaves your
                        browser.
                    </p>
                </div>

                <div className="flex flex-col items-center gap-3 rounded-xl border bg-card p-6">
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-violet-100 dark:bg-violet-900/30">
                        <Server className="h-6 w-6 text-violet-600 dark:text-violet-400" />
                    </div>
                    <h3 className="font-semibold">We Can't Read It</h3>
                    <p className="text-sm text-muted-foreground">
                        Even we can't access your data. It's truly private.
                    </p>
                </div>
            </div>

            <div className="mb-8 flex w-full max-w-xl flex-col items-center justify-center gap-6 rounded-xl border-2 border-dashed border-muted-foreground/20 p-6">
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

            <Button size="lg" onClick={onContinue} className="group gap-2 px-8">
                I Understand, Continue
                <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
            </Button>
        </div>
    );
}
