import { WholeAmount } from '@/components/cashflow/summary-card-parts';
import { PrivacyRevealButton } from '@/components/privacy-reveal-button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import { type DashboardEstimates } from '@/types/dashboard';
import { formatMonthName } from '@/utils/date';
import { __ } from '@/utils/i18n';

/**
 * A bar drawn as what the user typed, not as money that moved: a dashed
 * outline over a hatched fill, in muted tones in both themes.
 */
const ESTIMATE_BAR =
    'h-full rounded-full border border-dashed border-muted-foreground/70 bg-[repeating-linear-gradient(135deg,color-mix(in_oklab,var(--color-muted-foreground)_35%,transparent)_0_3px,transparent_3px_6px)]';

/**
 * The month the user typed in round figures during the onboarding, on the
 * dashboard of someone who has nothing else yet. Labelled as an estimate and
 * drawn dashed, so it never reads as movements; set against the spending the
 * user guessed. Only here: everywhere else estimates stay out of the totals.
 */
export function EstimatedSpendingCard({
    estimates,
    spendingGuess,
    guessCurrencyCode,
}: {
    estimates: DashboardEstimates;
    spendingGuess: number | null;
    guessCurrencyCode: string;
}) {
    const locale = useLocale();
    const month = formatMonthName(estimates.month, locale);
    const { currency_code: currencyCode, total } = estimates;

    return (
        <Card
            role="region"
            aria-label={__(':month spending', { month })}
            className="h-full"
        >
            <CardHeader className="flex-row items-center justify-between gap-3">
                <CardTitle>{__(':month spending', { month })}</CardTitle>
                <div className="flex items-center gap-2">
                    <PrivacyRevealButton className="-my-2" />
                    <Badge variant="outline" className="border-dashed">
                        {__('Estimate')}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <WholeAmount
                        amountInCents={total}
                        currencyCode={currencyCode}
                        size="2xl"
                        weight="semibold"
                        className="tabular-nums"
                    />
                    {spendingGuess !== null &&
                        guessCurrencyCode === currencyCode && (
                            <GuessComparison
                                guess={spendingGuess}
                                total={total}
                                currencyCode={currencyCode}
                            />
                        )}
                </div>

                <ul className="flex flex-col gap-3">
                    {estimates.rows.map((row) => (
                        <li key={row.name} className="flex flex-col gap-1.5">
                            <div className="flex items-center justify-between gap-3 text-sm">
                                <span className="truncate">{row.name}</span>
                                <WholeAmount
                                    amountInCents={row.amount}
                                    currencyCode={currencyCode}
                                    className="tabular-nums"
                                />
                            </div>
                            <div
                                className="h-2 rounded-full bg-muted"
                                data-testid="estimate-bar"
                            >
                                <div
                                    className={ESTIMATE_BAR}
                                    style={{
                                        width: `${barWidth(row.amount, total)}%`,
                                    }}
                                />
                            </div>
                        </li>
                    ))}
                </ul>

                <p className="text-xs text-muted-foreground">
                    {__(
                        "Dashed bars are what you typed, not movements. They're replaced when you import :month.",
                        { month },
                    )}
                </p>
            </CardContent>
        </Card>
    );
}

/** A row's share of the month, with a sliver left for the smallest ones. */
function barWidth(amount: number, total: number): number {
    if (total <= 0) {
        return 0;
    }

    return Math.max(2, Math.min(100, (amount / total) * 100));
}

function GuessComparison({
    guess,
    total,
    currencyCode,
}: {
    guess: number;
    total: number;
    currencyCode: string;
}) {
    const { formatAmount } = useFormatAmount();
    const whole = (amount: number) =>
        formatAmount(amount, currencyCode, { fractionDigits: 0 });

    return (
        <span className="text-sm text-muted-foreground">
            {total > guess
                ? __('you guessed :guess — already :over over', {
                      guess: whole(guess),
                      over: whole(total - guess),
                  })
                : __('you guessed :guess', { guess: whole(guess) })}
        </span>
    );
}
