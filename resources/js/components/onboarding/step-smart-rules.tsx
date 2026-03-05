import { StepButton } from '@/components/onboarding/step-button';
import { StepHeader } from '@/components/onboarding/step-header';
import { __ } from '@/utils/i18n';
import { Sparkles, Zap } from 'lucide-react';

interface StepSmartRulesProps {
    onContinue: () => void;
}

export function StepSmartRules({ onContinue }: StepSmartRulesProps) {
    return (
        <div className="flex animate-in flex-col items-center pb-4 duration-500 fade-in slide-in-from-bottom-4">
            <StepHeader
                icon={Zap}
                iconContainerClassName="bg-gradient-to-br from-yellow-400 to-amber-500"
                title={__('Smart Automation Rules')}
                description={__(
                    'Create rules to automatically categorize your transactions based on patterns you define.',
                )}
            />

            <div className="mb-5 grid w-full max-w-2xl gap-4 md:grid-cols-2">
                <div className="rounded-xl border bg-card p-5">
                    <div className="flex flex-row items-center gap-2">
                        <div className="mb-3 flex size-8 items-center justify-center rounded-lg bg-emerald-100 dark:bg-emerald-900/30">
                            <Sparkles className="size-5 text-emerald-600 dark:text-emerald-400" />
                        </div>
                        <h3 className="mb-2 font-semibold">
                            {__('Pattern Matching')}
                        </h3>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {__(
                            'Create rules like "If description contains \'AMAZON\', categorize as Shopping"',
                        )}
                    </p>
                </div>

                <div className="rounded-xl border bg-card p-5">
                    <div className="flex flex-row items-center gap-2">
                        <div className="mb-3 flex size-8 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/30">
                            <Zap className="size-5 text-blue-600 dark:text-blue-400" />
                        </div>
                        <h3 className="mb-2 font-semibold">
                            {__('Instant Application')}
                        </h3>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {__(
                            'Rules apply automatically when you import new transactions',
                        )}
                    </p>
                </div>
            </div>

            <StepButton text={__('Continue')} onClick={onContinue} />
        </div>
    );
}
