import { Alert, AlertDescription } from '@/components/ui/alert';
import { AmountDisplay } from '@/components/ui/amount-display';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useLocale } from '@/hooks/use-locale';
import {
    type AccountChoice,
    type PortfolioChoice,
} from '@/lib/broker-imports/broker-accounts';
import { cn } from '@/lib/utils';
import { formatAccountType, type Account } from '@/types/account';
import { formatDate, formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { AlertTriangle } from 'lucide-react';
import { type ReactNode } from 'react';

/*
 * The pieces every broker's step, file notice and summary strip are built
 * from, so each broker only says what its file holds.
 */

const CREATE = 'create';
const SKIP = 'skip';

function choiceValue(choice: PortfolioChoice): string {
    return choice.kind === 'existing' ? choice.accountId : choice.kind;
}

export function accountChoiceFrom(value: string): AccountChoice {
    return value === CREATE
        ? { kind: 'create' }
        : { kind: 'existing', accountId: value };
}

export function portfolioChoiceFrom(value: string): PortfolioChoice {
    return value === SKIP ? { kind: 'skip' } : accountChoiceFrom(value);
}

/** The first day among some dated rows, `YYYY-MM-DD`, or null for none. */
export function firstDay(rows: { date: string }[]): string | null {
    return rows.map((row) => row.date).sort()[0] ?? null;
}

function accountLabel(account: Account): string {
    return `${account.name} · ${account.bank?.name ?? formatAccountType(account.type)}`;
}

export function Panel({
    title,
    tag,
    hint,
    children,
}: {
    title: string;
    tag: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <section className="flex flex-col gap-4 rounded-lg border p-4">
            <div className="flex items-center justify-between gap-2">
                <h3 className="text-[15px] font-semibold">{title}</h3>
                <span className="rounded-md border bg-muted px-1.5 text-xs font-medium">
                    {tag}
                </span>
            </div>
            {hint && (
                <p className="text-[13px] text-muted-foreground">{hint}</p>
            )}
            {children}
        </section>
    );
}

export function AccountSelect<Choice extends PortfolioChoice>({
    id,
    value,
    accounts,
    createLabel,
    skipLabel,
    replacesFrom,
    parse,
    onChange,
}: {
    id: string;
    value: Choice;
    accounts: Account[];
    createLabel: string;
    skipLabel?: string;
    /** The first day whose balance the import writes, told when the account already exists. */
    replacesFrom: string | null;
    parse: (value: string) => Choice;
    onChange: (choice: Choice) => void;
}) {
    const locale = useLocale();

    return (
        <div className="flex flex-col gap-2">
            <Label htmlFor={id}>{__('Account')}</Label>
            <Select
                value={choiceValue(value)}
                onValueChange={(next) => onChange(parse(next))}
            >
                <SelectTrigger id={id}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {accounts.map((account) => (
                        <SelectItem key={account.id} value={account.id}>
                            {accountLabel(account)}
                        </SelectItem>
                    ))}
                    <SelectItem value={CREATE}>{createLabel}</SelectItem>
                    {skipLabel && (
                        <SelectItem value={SKIP}>{skipLabel}</SelectItem>
                    )}
                </SelectContent>
            </Select>
            {value.kind === 'existing' && replacesFrom && (
                <p className="text-xs text-muted-foreground">
                    {__(
                        "Its daily balances from :date on are replaced with the file's.",
                        { date: formatDateMedium(replacesFrom, locale) },
                    )}
                </p>
            )}
        </div>
    );
}

/**
 * Today's cash balance, which each day's is rebuilt backwards from: typed,
 * or read-only when the file says it.
 */
export function CashBalanceField({
    label,
    value,
    currencyCode,
    hint,
    onChange,
}: {
    label: string;
    value: number;
    currencyCode: string;
    hint: string;
    /** Left out when the balance comes from the file and cannot be changed. */
    onChange?: (value: number) => void;
}) {
    return (
        <div className="flex flex-col gap-2">
            {onChange ? (
                <>
                    <Label htmlFor="broker-cash-balance">{label}</Label>
                    <AmountInput
                        id="broker-cash-balance"
                        value={value}
                        onChange={onChange}
                        currencyCode={currencyCode}
                        allowNegative
                    />
                </>
            ) : (
                <>
                    <span className="text-sm font-medium">{label}</span>
                    <span className="flex h-9 items-center text-sm font-medium">
                        <AmountDisplay
                            amountInCents={value}
                            currencyCode={currencyCode}
                        />
                    </span>
                </>
            )}
            <p className="text-xs text-muted-foreground">{hint}</p>
        </div>
    );
}

/** What was invested, read-only, beside the value the user may type. */
export function InvestedAndValueFields({
    invested,
    currentValue,
    currencyCode,
    hint,
    onCurrentValueChange,
}: {
    invested: number;
    currentValue: number | null;
    currencyCode: string;
    hint: string;
    onCurrentValueChange: (value: number | null) => void;
}) {
    return (
        <>
            <div className="grid grid-cols-2 gap-3">
                <div className="flex flex-col gap-2">
                    <span className="text-sm font-medium">
                        {__('Invested')}
                    </span>
                    <span className="flex h-9 items-center text-sm font-medium">
                        <AmountDisplay
                            amountInCents={invested}
                            currencyCode={currencyCode}
                        />
                    </span>
                </div>
                <div className="flex flex-col gap-2">
                    <Label htmlFor="broker-portfolio-value">
                        {__('Current value')}
                    </Label>
                    <AmountInput
                        id="broker-portfolio-value"
                        value={currentValue ?? 0}
                        placeholder={__('The one the app shows')}
                        onChange={(value, isEmpty) =>
                            onCurrentValueChange(isEmpty ? null : value)
                        }
                        currencyCode={currencyCode}
                    />
                </div>
            </div>
            <p className="text-xs text-muted-foreground">{hint}</p>
        </>
    );
}

/** Something on a broker step worth a second look before importing. */
export function BrokerWarning({ children }: { children: ReactNode }) {
    return (
        <Alert className="border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
            <AlertTriangle />
            <AlertDescription className="text-inherit">
                {children}
            </AlertDescription>
        </Alert>
    );
}

/** Back and on to the preview, at the foot of a broker step. */
export function BrokerStepFooter({
    onBack,
    onNext,
}: {
    onBack: () => void;
    onNext: () => void;
}) {
    return (
        <div className="flex justify-between">
            <Button variant="outline" onClick={onBack}>
                {__('Back')}
            </Button>
            <Button onClick={onNext}>{__('Preview')}</Button>
        </div>
    );
}

/** "Mar 2024", in the reader's language. */
function monthLabel(day: string, locale: string): string {
    return formatDate(new Date(`${day}T00:00:00`), 'MMM yyyy', locale);
}

/** "Mar 2024 – Oct 2026", or one month when both fall in it. */
export function useMonthRange(from: string | null, to: string | null): string {
    const locale = useLocale();
    const firstMonth = from ? monthLabel(from, locale) : '';
    const lastMonth = to ? monthLabel(to, locale) : '';

    return firstMonth === lastMonth
        ? firstMonth
        : __(':from – :to', { from: firstMonth, to: lastMonth });
}

/** What the upload step says under a recognised file. */
export function BrokerFileNoticeCard({
    badge,
    title,
    children,
}: {
    badge: string;
    title: string;
    children: ReactNode;
}) {
    return (
        <div className="flex items-start gap-3 rounded-lg border bg-muted/40 px-4 py-3.5">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-foreground text-xs font-semibold text-background">
                {badge}
            </span>
            <div className="flex flex-col gap-1">
                <p className="text-sm font-medium">{title}</p>
                <p className="text-[13px] text-muted-foreground">{children}</p>
            </div>
        </div>
    );
}

export function SummaryTile({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1 rounded-lg border px-3.5 py-3">
            <span className="text-xs text-muted-foreground">{label}</span>
            <span className="text-base font-semibold">{children}</span>
        </div>
    );
}

/** The portfolio's tiles: invested, and with a typed value, that value and the gain. */
export function PortfolioSummaryTiles({
    invested,
    currentValue,
    currencyCode,
}: {
    invested: number;
    currentValue: number | null;
    currencyCode: string;
}) {
    const locale = useLocale();
    const gain = currentValue !== null ? currentValue - invested : null;

    return (
        <>
            <SummaryTile label={__('Portfolio · invested')}>
                <AmountDisplay
                    amountInCents={invested}
                    currencyCode={currencyCode}
                />
            </SummaryTile>
            {currentValue !== null && (
                <SummaryTile label={__('Portfolio · current value')}>
                    <AmountDisplay
                        amountInCents={currentValue}
                        currencyCode={currencyCode}
                    />
                </SummaryTile>
            )}
            {gain !== null && (
                <SummaryTile label={__('Gain')}>
                    <span
                        className={cn(
                            gain >= 0
                                ? 'text-green-700 dark:text-green-400'
                                : 'text-red-700 dark:text-red-400',
                        )}
                    >
                        <AmountDisplay
                            amountInCents={gain}
                            currencyCode={currencyCode}
                            showSign
                        />
                        {invested > 0 &&
                            ` · ${new Intl.NumberFormat(locale, {
                                style: 'percent',
                                maximumFractionDigits: 1,
                                signDisplay: 'exceptZero',
                            }).format(gain / invested)}`}
                    </span>
                </SummaryTile>
            )}
        </>
    );
}
