import { card } from '@/actions/App/Http/Controllers/MonthlySummaryController';
import { ShareCardDialog } from '@/components/share/share-card-dialog';
import { __ } from '@/utils/i18n';

/**
 * Posting one card off a monthly report.
 *
 * A thin wrapper over {@see ShareCardDialog}, which the progress screen's medals
 * share: the two shapes the networks want, both skins, the device's own share
 * sheet and saving the picture as the fallback. This used to be three download
 * links plus a light/dark switch for the whole section, which offered a 16:9
 * nothing unfurled and no way to hand the picture straight to an app.
 *
 * Nothing to withhold here, unlike a medal: the summary card template draws no
 * absolute amount at all, which is also why these are the ones that can afford
 * a public URL.
 */
export function ShareSummaryCardDialog({
    summaryId,
    period,
    card: cardType,
    label,
    children,
}: {
    summaryId: string;
    period: string;
    card: string;
    label: string;
    children: React.ReactNode;
}) {
    return (
        <ShareCardDialog
            title={__('Share your month')}
            description={__(
                'Pick a shape and a skin. Nothing leaves your device until you send it.',
            )}
            subject={label}
            url={({ format, theme, preview }) =>
                `${card({ summary: summaryId, card: cardType, format, theme }).url}${preview ? '?preview=1' : ''}`
            }
            filename={({ format, theme }) =>
                `whisper-money-${period}-${cardType}-${format}-${theme}.png`
            }
        >
            {children}
        </ShareCardDialog>
    );
}
