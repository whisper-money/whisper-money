import {
    UpgradeDialog,
    type UpsellSource,
} from '@/components/subscription/upgrade-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { usePriceTierChoice } from '@/hooks/use-price-tier';
import { type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Shown to accounts without a paid plan on a settings screen whose tokens only
 * work on Standard (the API and the MCP connector). "See plans" opens the
 * upgrade dialog in place, attributed to the screen it came from.
 */
export function ProUpsellAlert({
    source,
}: {
    source: Extract<UpsellSource, 'api' | 'mcp'>;
}) {
    const { pricing } = usePage<SharedData>().props;
    const trialDays =
        usePriceTierChoice().plans[pricing.defaultPlan]?.trial_days;
    const [open, setOpen] = useState(false);

    return (
        <>
            <Alert className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex flex-col gap-1">
                    <AlertTitle>
                        {source === 'api'
                            ? __('Create tokens with Standard')
                            : __('Connect assistants with Standard')}
                    </AlertTitle>
                    <AlertDescription>
                        {trialDays
                            ? __(
                                  'Free for :days days. You can still revoke the tokens you have.',
                                  { days: trialDays },
                              )
                            : __('You can still revoke the tokens you have.')}
                    </AlertDescription>
                </div>
                <Button
                    size="sm"
                    className="shrink-0 self-start sm:self-center"
                    onClick={() => setOpen(true)}
                >
                    {__('See plans')}
                </Button>
            </Alert>

            <UpgradeDialog open={open} onOpenChange={setOpen} source={source} />
        </>
    );
}
