import { useNewTransactionDialog } from '@/components/transactions/use-new-transaction-dialog';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    StatTile,
    StatTileLabel,
    StatTileValue,
} from '@/components/ui/stat-tile';
import { useLocale } from '@/hooks/use-locale';
import { formatMonthName } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { currentYearMonth } from './first-dashboard-copy';

/**
 * The cashflow card of a dashboard with no movement in the month yet: dashes
 * where the figures go, rather than three zeros that read as a month with no
 * income, and the way to add the first one.
 */
export function NoMovementsCashflowCard() {
    const locale = useLocale();
    const newTransaction = useNewTransactionDialog('full_dialog');
    const month = formatMonthName(currentYearMonth(), locale);

    return (
        <Card className="h-full">
            <CardHeader className="gap-1">
                <CardTitle>{__('Cashflow')}</CardTitle>
                <CardDescription>
                    {__(
                        "No :month movements yet. Income, spending and what's left over appear here as soon as you import or add them.",
                        { month },
                    )}
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col items-start gap-4">
                <div className="grid w-full grid-cols-3 gap-4">
                    {[__('Income'), __('Expenses'), __('Net')].map((label) => (
                        <StatTile key={label} size="sm">
                            <StatTileLabel>{label}</StatTileLabel>
                            <StatTileValue className="text-muted-foreground">
                                —
                            </StatTileValue>
                        </StatTile>
                    ))}
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={newTransaction.loading}
                    onClick={() => void newTransaction.show()}
                >
                    {__('Add a movement')}
                </Button>
            </CardContent>
            {newTransaction.dialog}
        </Card>
    );
}
