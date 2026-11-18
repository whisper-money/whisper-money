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
import { brokerCashAccountId } from '@/lib/broker-imports/broker-import';
import {
    cashAccountOptions,
    investedToday,
    isTradeRepublicBank,
    portfolioAccountOptions,
    TRADE_REPUBLIC_CASH_NAME,
    TRADE_REPUBLIC_PORTFOLIO_NAME,
    tradeRepublicCurrency,
    tradeRepublicSummary,
    type AccountChoice,
    type PortfolioChoice,
    type TradeRepublicImport,
    type TradeRepublicPlan,
} from '@/lib/broker-imports/trade-republic-import';
import { cn } from '@/lib/utils';
import { formatAccountType, type Account } from '@/types/account';
import { formatDate, formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { AlertTriangle } from 'lucide-react';
import { type ReactNode } from 'react';

interface ImportStepBrokerTradeRepublicProps {
    broker: TradeRepublicImport;
    accounts: Account[];
    onPlanChange: (plan: TradeRepublicPlan) => void;
    onNext: () => void;
    onBack: () => void;
}

const CREATE = 'create';
const SKIP = 'skip';

function choiceValue(choice: PortfolioChoice): string {
    return choice.kind === 'existing' ? choice.accountId : choice.kind;
}

function accountChoiceFrom(value: string): AccountChoice {
    return value === CREATE
        ? { kind: 'create' }
        : { kind: 'existing', accountId: value };
}

function portfolioChoiceFrom(value: string): PortfolioChoice {
    return value === SKIP ? { kind: 'skip' } : accountChoiceFrom(value);
}

/** The first day among some dated rows, `YYYY-MM-DD`, or null for none. */
function firstDay(rows: { date: string }[]): string | null {
    return rows.map((row) => row.date).sort()[0] ?? null;
}

function accountLabel(account: Account): string {
    return `${account.name} · ${account.bank?.name ?? formatAccountType(account.type)}`;
}

function Panel({
    title,
    tag,
    hint,
    children,
}: {
    title: string;
    tag: string;
    hint: string;
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
            <p className="text-[13px] text-muted-foreground">{hint}</p>
            {children}
        </section>
    );
}

function AccountSelect<Choice extends PortfolioChoice>({
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
 * Trade Republic's file carries the cash account and the securities bought
 * from it: this step says which account each goes to, the cash balance the
 * daily balances are rebuilt from, and what the portfolio is worth today.
 */
export function ImportStepBrokerTradeRepublic({
    broker,
    accounts,
    onPlanChange,
    onNext,
    onBack,
}: ImportStepBrokerTradeRepublicProps) {
    const { file, plan } = broker;
    const currencyCode = tradeRepublicCurrency(file);
    const cashOptions = cashAccountOptions(accounts);
    const portfolioOptions = portfolioAccountOptions(accounts);
    const invested = investedToday(file, broker.today);
    const securities = new Set(file.trades.map((trade) => trade.symbol)).size;
    const cashAccountId = brokerCashAccountId(broker);
    const cashAccount = cashOptions.find(
        (account) => account.id === cashAccountId,
    );
    const update = (changes: Partial<TradeRepublicPlan>) =>
        onPlanChange({ ...plan, ...changes });

    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-4 md:grid-cols-2">
                <Panel
                    title={__('Cash')}
                    tag={__(':count movements', { count: file.cash.length })}
                    hint={__(
                        'Card payments, transfers, direct debits, interest and dividends. Buys and sells of securities come out of it too.',
                    )}
                >
                    <AccountSelect
                        id="broker-cash-account"
                        value={plan.cash}
                        accounts={cashOptions}
                        createLabel={__('Create “:name” · :type', {
                            name: TRADE_REPUBLIC_CASH_NAME,
                            type: formatAccountType('checking'),
                        })}
                        replacesFrom={firstDay(file.cash)}
                        parse={accountChoiceFrom}
                        onChange={(cash) => update({ cash })}
                    />
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="broker-cash-balance">
                            {__('Cash balance today')}
                        </Label>
                        <AmountInput
                            id="broker-cash-balance"
                            value={plan.balanceToday}
                            onChange={(balanceToday) =>
                                update({ balanceToday })
                            }
                            currencyCode={currencyCode}
                            allowNegative
                        />
                        <p className="text-xs text-muted-foreground">
                            {__(
                                "Worked out from the file. If it doesn't match the app, correct it and we rebuild each day's balance backwards from it.",
                            )}
                        </p>
                    </div>
                </Panel>

                <Panel
                    title={__('Securities portfolio')}
                    tag={__(':trades trades · :securities securities', {
                        trades: file.trades.length,
                        securities,
                    })}
                    hint={__(
                        'Stocks, ETFs, savings plans and crypto. We keep what was invested day by day.',
                    )}
                >
                    <AccountSelect
                        id="broker-portfolio-account"
                        value={plan.portfolio}
                        accounts={portfolioOptions}
                        createLabel={__('Create “:name” · :type', {
                            name: TRADE_REPUBLIC_PORTFOLIO_NAME,
                            type: formatAccountType('investment'),
                        })}
                        skipLabel={__("Don't import the portfolio")}
                        replacesFrom={firstDay(file.trades)}
                        parse={portfolioChoiceFrom}
                        onChange={(portfolio) => update({ portfolio })}
                    />
                    {plan.portfolio.kind !== 'skip' && (
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
                                        value={plan.currentValue ?? 0}
                                        placeholder={__(
                                            'The one the app shows',
                                        )}
                                        onChange={(value, isEmpty) =>
                                            update({
                                                currentValue: isEmpty
                                                    ? null
                                                    : value,
                                            })
                                        }
                                        currencyCode={currencyCode}
                                    />
                                </div>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {__(
                                    'Copy the total value Trade Republic shows. Leave it empty and we use what was invested until you update it.',
                                )}
                            </p>
                        </>
                    )}
                </Panel>
            </div>

            {cashAccount && !isTradeRepublicBank(cashAccount.bank) && (
                <Alert className="border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                    <AlertTriangle />
                    <AlertDescription className="text-inherit">
                        {cashAccount.bank
                            ? __(
                                  'You picked “:account”, which is at :bank. Sure the Trade Republic cash goes there? You can create a new account above.',
                                  {
                                      account: cashAccount.name,
                                      bank: cashAccount.bank.name,
                                  },
                              )
                            : __(
                                  'You picked “:account”, which is not a Trade Republic account. Sure the Trade Republic cash goes there? You can create a new account above.',
                                  { account: cashAccount.name },
                              )}
                    </AlertDescription>
                </Alert>
            )}

            <div className="flex justify-between">
                <Button variant="outline" onClick={onBack}>
                    {__('Back')}
                </Button>
                <Button onClick={onNext}>{__('Preview')}</Button>
            </div>
        </div>
    );
}

/** "Mar 2024", in the reader's language. */
function monthLabel(day: string, locale: string): string {
    return formatDate(new Date(`${day}T00:00:00`), 'MMM yyyy', locale);
}

/** What the upload step says under a Trade Republic file. */
export function TradeRepublicFileNotice({
    broker,
}: {
    broker: TradeRepublicImport;
}) {
    const locale = useLocale();
    const { movements, from, to } = tradeRepublicSummary(broker.file);
    const firstMonth = from ? monthLabel(from, locale) : '';
    const lastMonth = to ? monthLabel(to, locale) : '';
    const range =
        firstMonth === lastMonth
            ? firstMonth
            : __(':from – :to', { from: firstMonth, to: lastMonth });

    return (
        <div className="flex items-start gap-3 rounded-lg border bg-muted/40 px-4 py-3.5">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-foreground text-xs font-semibold text-background">
                TR
            </span>
            <div className="flex flex-col gap-1">
                <p className="text-sm font-medium">
                    {__('This is a Trade Republic export')}
                </p>
                <p className="text-[13px] text-muted-foreground">
                    {__(
                        ":count movements, :range. No column mapping needed: you'll choose the accounts next.",
                        { count: movements, range },
                    )}
                </p>
            </div>
        </div>
    );
}

function SummaryTile({
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

/** The strip above the preview: where the two accounts will stand once imported. */
export function TradeRepublicSummaryStrip({
    broker,
}: {
    broker: TradeRepublicImport;
}) {
    const locale = useLocale();
    const { file, plan } = broker;
    const currencyCode = tradeRepublicCurrency(file);
    const invested = investedToday(file, broker.today);
    const showsPortfolio = plan.portfolio.kind !== 'skip';
    const gain =
        showsPortfolio && plan.currentValue !== null
            ? plan.currentValue - invested
            : null;

    return (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <SummaryTile label={__('Cash today')}>
                <AmountDisplay
                    amountInCents={plan.balanceToday}
                    currencyCode={currencyCode}
                />
            </SummaryTile>
            {showsPortfolio && (
                <SummaryTile label={__('Portfolio · invested')}>
                    <AmountDisplay
                        amountInCents={invested}
                        currencyCode={currencyCode}
                    />
                </SummaryTile>
            )}
            {showsPortfolio && plan.currentValue !== null && (
                <SummaryTile label={__('Portfolio · current value')}>
                    <AmountDisplay
                        amountInCents={plan.currentValue}
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
        </div>
    );
}
