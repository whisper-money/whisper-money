import { DocGuideLink } from '@/components/documentation/doc-guide-link';
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
import { type BrokerImport } from '@/lib/broker-imports/broker-import';
import { brokerMemoryInUse } from '@/lib/broker-imports/broker-memory';
import { type BrokerLinkConfig } from '@/lib/broker-imports/types';
import { cn } from '@/lib/utils';
import { formatAccountType, type Account } from '@/types/account';
import { formatDate, formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { AlertTriangle, Link2 } from 'lucide-react';
import { Fragment, useState, type ReactNode } from 'react';

/*
 * The pieces every broker's step, file notice and summary strip are built
 * from, so each broker only says what its file holds.
 */

/** The documentation page on importing a broker's export. */
const BROKER_GUIDE_SLUG = 'transactions/import-from-a-broker';

/** The section of that page each broker's files are explained in. */
const BROKER_GUIDE_SECTIONS: Record<BrokerImport['kind'], string> = {
    trade_republic: 'trade-republic',
    myinvestor_cash: 'myinvestor',
    myinvestor_securities: 'myinvestor',
};

/** A link to the guide's section on this broker, in a new tab. */
export function BrokerGuideLink({
    kind,
    className,
    children,
}: {
    kind?: BrokerImport['kind'];
    className?: string;
    children: ReactNode;
}) {
    return (
        <DocGuideLink
            slug={BROKER_GUIDE_SLUG}
            anchor={kind ? BROKER_GUIDE_SECTIONS[kind] : undefined}
            className={cn('text-[13px]', className)}
        >
            {children}
        </DocGuideLink>
    );
}

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
            {value.kind === 'existing' && (
                <ReplacesFromNote replacesFrom={replacesFrom} />
            )}
        </div>
    );
}

/** That an existing account's daily balances from the file's first day on are overwritten. */
export function ReplacesFromNote({
    replacesFrom,
}: {
    replacesFrom: string | null;
}) {
    const locale = useLocale();

    return replacesFrom ? (
        <p className="text-xs text-muted-foreground">
            {__(
                "Its daily balances from :date on are replaced with the file's.",
                { date: formatDateMedium(replacesFrom, locale) },
            )}
        </p>
    ) : null;
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

/** What the last import said about the portfolio, beside today's figures. */
function SinceLastTime({
    invested,
    remembered,
    currencyCode,
}: {
    invested: number;
    remembered: BrokerLinkConfig | null;
    currencyCode: string;
}) {
    const last = remembered?.lastInvestedMinor ?? null;

    if (last === null || last === invested) {
        return null;
    }

    return (
        <span className="text-xs font-normal text-muted-foreground">
            <AmountDisplay
                amountInCents={invested - last}
                currencyCode={currencyCode}
                showSign
            />{' '}
            {__('since last time')}
        </span>
    );
}

function LastValue({
    remembered,
    currencyCode,
}: {
    remembered: BrokerLinkConfig | null;
    currencyCode: string;
}) {
    const locale = useLocale();
    const value = remembered?.lastValueMinor ?? null;
    const day = remembered?.lastValueDate ?? null;

    if (value === null || day === null) {
        return null;
    }

    return (
        <p className="text-xs text-muted-foreground">
            {__('Last time you entered')}{' '}
            <AmountDisplay amountInCents={value} currencyCode={currencyCode} />{' '}
            ({formatDateMedium(day, locale)}).
        </p>
    );
}

/** What was invested, read-only, beside the value the user may type. */
export function InvestedAndValueFields({
    invested,
    currentValue,
    currencyCode,
    hint,
    remembered = null,
    onCurrentValueChange,
}: {
    invested: number;
    currentValue: number | null;
    currencyCode: string;
    hint: string;
    /** The last import of this broker, to show how things moved since. */
    remembered?: BrokerLinkConfig | null;
    onCurrentValueChange: (value: number | null) => void;
}) {
    return (
        <>
            <div className="grid grid-cols-2 gap-3">
                <div className="flex flex-col gap-2">
                    <span className="text-sm font-medium">
                        {__('Invested')}
                    </span>
                    <span className="flex h-9 flex-wrap items-center gap-x-1.5 text-sm font-medium">
                        <AmountDisplay
                            amountInCents={invested}
                            currencyCode={currencyCode}
                        />
                        <SinceLastTime
                            invested={invested}
                            remembered={remembered}
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
            <LastValue remembered={remembered} currencyCode={currencyCode} />
            <p className="text-xs text-muted-foreground">{hint}</p>
        </>
    );
}

/**
 * Whether a broker step shows the accounts of last time in a compact box
 * instead of the pickers, and the way to the pickers. Once the user asks to
 * change them, the pickers stay.
 */
export function useRememberedAccounts(
    broker: BrokerImport,
    /** Whether the box finds the accounts to name: without them, the pickers. */
    accountsFound: boolean,
): {
    /** The last import, while the plan still goes where it went. */
    remembered: BrokerLinkConfig | null;
    showPickers: boolean;
    change: () => void;
} {
    const [changing, setChanging] = useState(false);
    const remembered = brokerMemoryInUse(broker)?.config ?? null;

    return {
        remembered,
        showPickers: changing || remembered === null || !accountsFound,
        change: () => setChanging(true),
    };
}

/** An account the box names, with what it holds when the import has more than one. */
export interface RememberedAccount {
    name: string;
    role?: string;
}

/** "Last import: <date>", naming the accounts it went to, with a way to change them. */
export function RememberedAccountsBox({
    importedAt,
    accounts,
    onChange,
}: {
    importedAt: string;
    accounts: RememberedAccount[];
    onChange: () => void;
}) {
    const locale = useLocale();
    // The names joined the reader's way ("A and B", "A y B"), each kept apart
    // so it can be bold and carry its role.
    let named = 0;
    const parts = new Intl.ListFormat(locale, { type: 'conjunction' })
        .formatToParts(accounts.map((account) => account.name))
        .map((part) => ({
            text: part.value,
            account: part.type === 'element' ? accounts[named++] : null,
        }));

    return (
        <div className="flex flex-col gap-1 rounded-lg border bg-muted/40 px-4 py-3.5">
            <span className="text-sm font-medium">
                {__('Last import: :date', {
                    date: formatDateMedium(importedAt, locale),
                })}
            </span>
            <p className="text-[13px] text-muted-foreground">
                {__('Same accounts as last time:')}{' '}
                {parts.map(({ text, account }, position) =>
                    account ? (
                        <Fragment key={position}>
                            <strong className="font-medium text-foreground">
                                {text}
                            </strong>
                            {account.role && ` (${account.role})`}
                        </Fragment>
                    ) : (
                        <Fragment key={position}>{text}</Fragment>
                    ),
                )}
                .{' '}
                <Button
                    variant="link"
                    className="h-auto p-0 text-[13px]"
                    onClick={onChange}
                >
                    {__('Change')}
                </Button>
            </p>
        </div>
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

/**
 * Says the account the cash goes to is connected to the bank, whose sync
 * brings its movements and balance, so the file's are left out.
 */
export function BankConnectedNotice({ children }: { children: ReactNode }) {
    return (
        <Alert className="border-sky-300 bg-sky-50 text-sky-900 dark:border-sky-500/40 dark:bg-sky-500/10 dark:text-sky-200">
            <Link2 />
            <AlertDescription className="text-inherit">
                {children}
            </AlertDescription>
        </Alert>
    );
}

/** Back and on to the preview, at the foot of a broker step. */
export function BrokerStepFooter({
    kind,
    onBack,
    onNext,
    nextDisabled = false,
}: {
    /** The broker whose section of the guide the footer links to. */
    kind: BrokerImport['kind'];
    onBack: () => void;
    onNext: () => void;
    /** Nothing would come in, so there is nothing to preview. */
    nextDisabled?: boolean;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <Button variant="outline" onClick={onBack}>
                {__('Back')}
            </Button>
            <div className="flex flex-wrap items-center justify-end gap-4">
                <BrokerGuideLink kind={kind}>
                    {__('What this import does')}
                </BrokerGuideLink>
                <Button onClick={onNext} disabled={nextDisabled}>
                    {__('Preview')}
                </Button>
            </div>
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
    kind,
    badge,
    title,
    children,
}: {
    /** The broker whose section of the guide the notice links to. */
    kind: BrokerImport['kind'];
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
                <BrokerGuideLink kind={kind} className="self-start">
                    {__('Read the full guide')}
                </BrokerGuideLink>
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
