import { StepCallout, StepScreen } from '@/components/onboarding/step-screen';
import { Progress } from '@/components/ui/progress';
import { __ } from '@/utils/i18n';

interface StepImportProgressProps {
    accountName: string;
    imported: number;
    total: number;
}

export function StepImportProgress({
    accountName,
    imported,
    total,
}: StepImportProgressProps) {
    const percentage = total > 0 ? Math.round((imported / total) * 100) : 0;

    return (
        <StepScreen
            title={__('Bringing them in')}
            description={__(
                ':count movements into :account. This takes a few seconds.',
                { count: total, account: accountName },
            )}
        >
            <div className="flex flex-col gap-6">
                <div className="flex flex-col gap-3">
                    <Progress value={percentage} className="h-2" />
                    <div className="flex items-center justify-between text-sm text-muted-foreground tabular-nums">
                        <span>
                            {__(':imported of :total', { imported, total })}
                        </span>
                        <span>{percentage}%</span>
                    </div>
                </div>

                <StepCallout>
                    {__(
                        'Leaving this screen stops it where it got to — the movements already in stay in, and the rest are not saved. Waiting the few seconds is the cleaner way out.',
                    )}
                </StepCallout>
            </div>
        </StepScreen>
    );
}
