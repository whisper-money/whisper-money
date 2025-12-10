import { Button } from '@/components/ui/button';
import {
    ArrowRight,
    Bot,
    Eye,
    EyeOff,
    Shield,
    Sparkles,
    Zap,
} from 'lucide-react';

interface StepSmartRulesProps {
    onContinue: () => void;
}

export function StepSmartRules({ onContinue }: StepSmartRulesProps) {
    return (
        <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
            <div className="mb-8 flex h-20 w-20 animate-in items-center justify-center rounded-full bg-gradient-to-br from-yellow-400 to-amber-500 shadow-lg duration-500 zoom-in">
                <Zap className="h-10 w-10 text-white" />
            </div>

            <h1 className="mb-4 text-center text-3xl font-bold tracking-tight">
                Smart Automation Rules
            </h1>

            <p className="mb-8 max-w-lg text-center text-muted-foreground">
                Create rules to automatically categorize your transactions based
                on patterns you define.
            </p>

            <div className="mb-6 grid w-full max-w-2xl gap-4 md:grid-cols-2">
                <div className="rounded-xl border bg-card p-5">
                    <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-100 dark:bg-emerald-900/30">
                        <Sparkles className="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <h3 className="mb-2 font-semibold">Pattern Matching</h3>
                    <p className="text-sm text-muted-foreground">
                        Create rules like "If description contains 'AMAZON',
                        categorize as Shopping"
                    </p>
                </div>

                <div className="rounded-xl border bg-card p-5">
                    <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/30">
                        <Zap className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                    </div>
                    <h3 className="mb-2 font-semibold">Instant Application</h3>
                    <p className="text-sm text-muted-foreground">
                        Rules apply automatically when you import new
                        transactions
                    </p>
                </div>
            </div>

            <div className="mb-8 w-full max-w-2xl rounded-xl border-2 border-amber-200 bg-amber-50 p-6 dark:border-amber-900/50 dark:bg-amber-900/20">
                <div className="mb-4 flex items-center gap-3">
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-amber-200 dark:bg-amber-800">
                        <Shield className="h-6 w-6 text-amber-700 dark:text-amber-300" />
                    </div>
                    <div>
                        <h3 className="font-semibold text-amber-900 dark:text-amber-100">
                            Why No AI Auto-Categorization?
                        </h3>
                        <p className="text-sm text-amber-800 dark:text-amber-200">
                            Privacy comes first
                        </p>
                    </div>
                </div>

                <div className="space-y-3">
                    <div className="flex items-start gap-3">
                        <div className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
                            <Bot className="h-3.5 w-3.5 text-red-600 dark:text-red-400" />
                        </div>
                        <div>
                            <p className="text-sm font-medium text-amber-900 dark:text-amber-100">
                                AI requires sending your data to external
                                servers
                            </p>
                            <p className="text-xs text-amber-700 dark:text-amber-300">
                                This would break our end-to-end encryption
                                promise
                            </p>
                        </div>
                    </div>

                    <div className="flex items-start gap-3">
                        <div className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                            <EyeOff className="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                        </div>
                        <div>
                            <p className="text-sm font-medium text-amber-900 dark:text-amber-100">
                                Your rules run entirely in your browser
                            </p>
                            <p className="text-xs text-amber-700 dark:text-amber-300">
                                We never see your transaction descriptions
                            </p>
                        </div>
                    </div>

                    <div className="flex items-start gap-3">
                        <div className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30">
                            <Eye className="h-3.5 w-3.5 text-blue-600 dark:text-blue-400" />
                        </div>
                        <div>
                            <p className="text-sm font-medium text-amber-900 dark:text-amber-100">
                                You're in complete control
                            </p>
                            <p className="text-xs text-amber-700 dark:text-amber-300">
                                Create, edit, and delete rules anytime
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <Button size="lg" onClick={onContinue} className="group gap-2 px-8">
                Continue to Import
                <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
            </Button>
        </div>
    );
}
