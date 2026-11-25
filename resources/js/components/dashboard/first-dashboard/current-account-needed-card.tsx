import { CreateAccountDialog } from '@/components/accounts/create-account-dialog';
import { useImportDialog } from '@/components/transactions/use-import-dialog';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardTitle,
} from '@/components/ui/card';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { __ } from '@/utils/i18n';

/**
 * In place of the spending and cashflow cards on the dashboard of a user who
 * only brought balances (a fund, a pension, a property): those cards read
 * movements, so it says what they need, an account money is spent from, and
 * offers the two ways to add one.
 */
export function CurrentAccountNeededCard({
    spendingGuess,
    currencyCode,
    canImport,
}: {
    spendingGuess: number | null;
    currencyCode: string;
    /**
     * Whether an account can take a bank file. Without one the importer could
     * only say to create the account first, so the account is the one action.
     */
    canImport: boolean;
}) {
    const importer = useImportDialog();
    const { formatAmount } = useFormatAmount();

    return (
        <Card
            role="region"
            aria-label={__('Spending and cashflow need a current account')}
        >
            <CardContent className="grid items-center gap-8 md:grid-cols-2">
                <div className="flex flex-col gap-3.5">
                    <CardTitle
                        role="heading"
                        aria-level={2}
                        className="text-lg"
                    >
                        {__('Spending and cashflow need a current account')}
                    </CardTitle>
                    <CardDescription className="leading-relaxed">
                        {spendingGuess === null
                            ? __(
                                  "An investment moves as one number. Add the account your salary lands in and this space shows where the money goes each month and what's left over.",
                              )
                            : __(
                                  "An investment moves as one number. Add the account your salary lands in and this space shows where the money goes each month, what's left over, and how it compares with your guess of :guess.",
                                  {
                                      guess: formatAmount(
                                          spendingGuess,
                                          currencyCode,
                                          { fractionDigits: 0 },
                                      ),
                                  },
                              )}
                    </CardDescription>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        {canImport && (
                            <Button onClick={() => void importer.show(null)}>
                                {__('Import a bank file')}
                            </Button>
                        )}
                        <CreateAccountDialog
                            initialMode="manual"
                            trigger={
                                <Button
                                    variant={canImport ? 'outline' : 'default'}
                                >
                                    {__('Add an account by hand')}
                                </Button>
                            }
                        />
                    </div>
                </div>
                <SpendingPreview />
            </CardContent>
            {importer.dialog}
        </Card>
    );
}

/** What the space fills with, as grey bars: decoration, hidden from readers. */
function SpendingPreview() {
    return (
        <div
            aria-hidden
            className="hidden flex-col gap-3 rounded-xl border border-dashed bg-muted/40 p-5 md:flex"
        >
            <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {__('Preview')}
            </span>
            <div className="h-2.5 w-[70%] rounded-full bg-muted" />
            <div className="h-2.5 w-[46%] rounded-full bg-muted" />
            <div className="h-2.5 w-[30%] rounded-full bg-muted" />
            <div className="grid grid-cols-3 gap-2.5 pt-1.5">
                <div className="h-8 rounded-lg bg-muted" />
                <div className="h-8 rounded-lg bg-muted" />
                <div className="h-8 rounded-lg bg-muted" />
            </div>
        </div>
    );
}
