import { useImportDialog } from '@/components/transactions/use-import-dialog';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardTitle,
} from '@/components/ui/card';
import { useLocale } from '@/hooks/use-locale';
import { sendImportLink } from '@/lib/send-import-link';
import { type DashboardEstimates } from '@/types/dashboard';
import { formatMonthName } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { useState } from 'react';

type LinkStatus = 'idle' | 'sending' | 'sent';

/**
 * "Swap your estimates for the real thing": the one nudge on the dashboard of
 * a user whose only movements are the round figures typed in during the
 * onboarding. It stands where the "Finish setting up" card would, and offers
 * the importer, or the emailed link to import from another computer.
 */
export function EstimatesSwapCard({
    estimates,
}: {
    estimates: DashboardEstimates;
}) {
    const locale = useLocale();
    const importer = useImportDialog();
    const [linkStatus, setLinkStatus] = useState<LinkStatus>('idle');
    const accountId = estimates.account_id;
    const month = formatMonthName(estimates.month, locale);

    const handleSendLink = async (id: string) => {
        setLinkStatus('sending');
        setLinkStatus((await sendImportLink(id)) ? 'sent' : 'idle');
    };

    return (
        <Card
            size="sm"
            role="region"
            aria-label={__('Swap your estimates for the real thing')}
            className="border-foreground/60"
        >
            <CardContent className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div className="flex max-w-3xl flex-col gap-1.5">
                    <CardTitle
                        role="heading"
                        aria-level={2}
                        className="text-base"
                    >
                        {__('Swap your estimates for the real thing')}
                    </CardTitle>
                    <CardDescription>
                        {estimates.count === 1
                            ? __(
                                  "You typed :month in round figures. Import your bank's export and we replace the estimate with your real movements — and start the spending history from there.",
                                  { month },
                              )
                            : __(
                                  "You typed :month in round figures. Import your bank's export and we replace the :count estimates with your real movements — and start the spending history from there.",
                                  { month, count: estimates.count },
                              )}
                    </CardDescription>
                </div>
                <div className="flex flex-col gap-2 sm:flex-row">
                    <Button onClick={() => void importer.show(accountId)}>
                        {__('Import a bank file')}
                    </Button>
                    {accountId && (
                        <Button
                            variant="outline"
                            disabled={linkStatus !== 'idle'}
                            onClick={() => void handleSendLink(accountId)}
                        >
                            {linkStatus === 'sent'
                                ? __('Link sent')
                                : __('Email me a link')}
                        </Button>
                    )}
                </div>
            </CardContent>
            {importer.dialog}
        </Card>
    );
}
