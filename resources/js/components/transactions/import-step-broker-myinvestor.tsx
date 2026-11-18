import { FOOTNOTE } from '@/components/transactions/import-section';
import {
    CompactTransactionTable,
    type CompactRow,
} from '@/components/transactions/import-step-preview';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/hooks/use-locale';
import {
    cashAccountOptions,
    chosenAccountId,
    portfolioAccountOptions,
} from '@/lib/broker-imports/broker-accounts';
import {
    fileBalanceToday,
    investedOrders,
    MYINVESTOR_CASH_NAME,
    MYINVESTOR_PORTFOLIO_NAME,
    myInvestorCashSummary,
    myInvestorCurrency,
    myInvestorInvestedToday,
    myInvestorSecuritiesSummary,
    type MyInvestorCashImport,
    type MyInvestorCashPlan,
    type MyInvestorSecuritiesImport,
    type MyInvestorSecuritiesPlan,
} from '@/lib/broker-imports/myinvestor-import';
import { type Trade } from '@/lib/broker-imports/types';
import { formatAccountType, type Account } from '@/types/account';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Info } from 'lucide-react';
import {
    accountChoiceFrom,
    AccountSelect,
    BrokerFileNoticeCard,
    BrokerStepFooter,
    BrokerWarning,
    CashBalanceField,
    firstDay,
    InvestedAndValueFields,
    Panel,
    PortfolioSummaryTiles,
    RememberedAccountsBox,
    SummaryTile,
    useMonthRange,
    useRememberedAccounts,
} from './import-step-broker-parts';

interface StepProps<Broker, Plan> {
    broker: Broker;
    accounts: Account[];
    onPlanChange: (plan: Plan) => void;
    onNext: () => void;
    onBack: () => void;
}

/**
 * MyInvestor's current-account file: which account its movements go to,
 * and today's balance, which the file says when it has a balance column.
 */
export function ImportStepBrokerMyInvestorCash({
    broker,
    accounts,
    onPlanChange,
    onNext,
    onBack,
}: StepProps<MyInvestorCashImport, MyInvestorCashPlan>) {
    const locale = useLocale();
    const { file, plan } = broker;
    const balanceFromFile = fileBalanceToday(file) !== null;
    const { to } = myInvestorCashSummary(file);
    const cashOptions = cashAccountOptions(accounts);
    const { remembered, showPickers, change } = useRememberedAccounts(broker);
    const cashAccount = cashOptions.find(
        (account) => account.id === chosenAccountId(plan.cash),
    );

    return (
        <div className="flex flex-col gap-4">
            {!showPickers && remembered && cashAccount && (
                <RememberedAccountsBox
                    importedAt={remembered.lastImportedAt}
                    accounts={[{ name: cashAccount.name }]}
                    onChange={change}
                />
            )}
            <Panel
                title={__('Current account')}
                tag={__(':count movements', { count: file.movements.length })}
                hint={__(
                    'Card payments, transfers, Bizum, direct debits and interest.',
                )}
            >
                {showPickers && (
                    <AccountSelect
                        id="broker-cash-account"
                        value={plan.cash}
                        accounts={cashOptions}
                        createLabel={__('Create “:name” · :type', {
                            name: MYINVESTOR_CASH_NAME,
                            type: formatAccountType('savings'),
                        })}
                        replacesFrom={firstDay(file.movements)}
                        parse={accountChoiceFrom}
                        onChange={(cash) => onPlanChange({ ...plan, cash })}
                    />
                )}
                <CashBalanceField
                    label={__('Balance today')}
                    value={plan.balanceToday}
                    currencyCode={myInvestorCurrency(file)}
                    hint={
                        balanceFromFile
                            ? __(
                                  "The file's last balance, on :date. Each day's balance comes from the file too.",
                                  {
                                      date: to
                                          ? formatDateMedium(to, locale)
                                          : '',
                                  },
                              )
                            : __(
                                  "Worked out from the file. If it doesn't match the app, correct it and we rebuild each day's balance backwards from it.",
                              )
                    }
                    onChange={
                        balanceFromFile
                            ? undefined
                            : (balanceToday) =>
                                  onPlanChange({ ...plan, balanceToday })
                    }
                />
            </Panel>

            <BrokerStepFooter onBack={onBack} onNext={onNext} />
        </div>
    );
}

/**
 * MyInvestor's securities file: which account keeps what was invested, and
 * what the portfolio is worth today.
 */
export function ImportStepBrokerMyInvestorSecurities({
    broker,
    accounts,
    onPlanChange,
    onNext,
    onBack,
}: StepProps<MyInvestorSecuritiesImport, MyInvestorSecuritiesPlan>) {
    const { file, plan } = broker;
    const securities = new Set(
        investedOrders(file).map((order) => order.symbol),
    ).size;
    const portfolioOptions = portfolioAccountOptions(accounts);
    const { remembered, showPickers, change } = useRememberedAccounts(broker);
    const portfolioAccount = portfolioOptions.find(
        (account) => account.id === chosenAccountId(plan.portfolio),
    );

    return (
        <div className="flex flex-col gap-4">
            {!showPickers && remembered && portfolioAccount && (
                <RememberedAccountsBox
                    importedAt={remembered.lastImportedAt}
                    accounts={[{ name: portfolioAccount.name }]}
                    onChange={change}
                />
            )}
            <Panel
                title={__('Securities portfolio')}
                tag={__(':count securities', { count: securities })}
                hint={__(
                    'Funds, ETFs, stocks and pension plans. We keep what was invested day by day.',
                )}
            >
                {showPickers && (
                    <AccountSelect
                        id="broker-portfolio-account"
                        value={plan.portfolio}
                        accounts={portfolioOptions}
                        createLabel={__('Create “:name” · :type', {
                            name: MYINVESTOR_PORTFOLIO_NAME,
                            type: formatAccountType('investment'),
                        })}
                        replacesFrom={firstDay(investedOrders(file))}
                        parse={accountChoiceFrom}
                        onChange={(portfolio) =>
                            onPlanChange({ ...plan, portfolio })
                        }
                    />
                )}
                <InvestedAndValueFields
                    invested={myInvestorInvestedToday(file, broker.today)}
                    currentValue={plan.currentValue}
                    currencyCode={myInvestorCurrency(file)}
                    hint={__(
                        'Copy the total value MyInvestor shows. Leave it empty and we use what was invested until you update it.',
                    )}
                    remembered={remembered}
                    onCurrentValueChange={(currentValue) =>
                        onPlanChange({ ...plan, currentValue })
                    }
                />
                <p className="flex items-start gap-2 text-xs text-muted-foreground">
                    <Info className="mt-px size-3.5 shrink-0" />
                    {__(
                        "Transfers between funds don't change what was invested: the money leaves one fund and goes into another.",
                    )}
                </p>
            </Panel>

            {file.unassigned.length > 0 && (
                <BrokerWarning>
                    {file.unassigned.length === 1
                        ? __(
                              "1 pension-plan contribution has no ISIN. It counts toward invested but can't be matched to a fund.",
                          )
                        : __(
                              ":count pension-plan contributions have no ISIN. They count toward invested but can't be matched to a fund.",
                              { count: file.unassigned.length },
                          )}
                </BrokerWarning>
            )}

            <BrokerStepFooter onBack={onBack} onNext={onNext} />
        </div>
    );
}

/** What the upload step says under a MyInvestor current-account file. */
export function MyInvestorCashFileNotice({
    broker,
}: {
    broker: MyInvestorCashImport;
}) {
    const { count, from, to } = myInvestorCashSummary(broker.file);
    const range = useMonthRange(from, to);

    return (
        <BrokerFileNoticeCard
            badge="MI"
            title={__('This is a MyInvestor current-account export')}
        >
            {__(
                ":count movements, :range. No column mapping needed: you'll choose the account next.",
                { count, range },
            )}
        </BrokerFileNoticeCard>
    );
}

/** What the upload step says under a MyInvestor securities file. */
export function MyInvestorSecuritiesFileNotice({
    broker,
}: {
    broker: MyInvestorSecuritiesImport;
}) {
    const { count, from, to } = myInvestorSecuritiesSummary(broker.file);
    const range = useMonthRange(from, to);

    return (
        <BrokerFileNoticeCard
            badge="MI"
            title={__('MyInvestor securities operations')}
        >
            {__(
                ':count operations, :range. The current-account cash comes in another Excel: import it separately.',
                { count, range },
            )}
        </BrokerFileNoticeCard>
    );
}

/** The strip above the preview: where the current account will stand. */
export function MyInvestorCashSummaryStrip({
    broker,
}: {
    broker: MyInvestorCashImport;
}) {
    return (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <SummaryTile label={__('Balance today')}>
                <AmountDisplay
                    amountInCents={broker.plan.balanceToday}
                    currencyCode={myInvestorCurrency(broker.file)}
                />
            </SummaryTile>
        </div>
    );
}

/** The strip above the preview: where the portfolio will stand. */
export function MyInvestorSecuritiesSummaryStrip({
    broker,
}: {
    broker: MyInvestorSecuritiesImport;
}) {
    return (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <PortfolioSummaryTiles
                invested={myInvestorInvestedToday(broker.file, broker.today)}
                currentValue={broker.plan.currentValue}
                currencyCode={myInvestorCurrency(broker.file)}
            />
        </div>
    );
}

function operationLabel(order: Trade): string {
    if (order.isTransfer) {
        return __('Transfer · :name', { name: order.name });
    }

    return order.side === 'buy'
        ? __('Buy · :name', { name: order.name })
        : __('Sell · :name', { name: order.name });
}

/** An order as a row: money into the portfolio is positive, out of it negative. */
function operationRow(order: Trade): CompactRow {
    return {
        key: order.externalId,
        date: order.date,
        description: operationLabel(order),
        amountInCents: order.side === 'buy' ? order.amount : -order.amount,
        currencyCode: order.currency,
    };
}

/**
 * The preview of a securities file. It brings no transactions, so there is
 * nothing to pick: the operations are listed for reference, and importing
 * writes the portfolio's daily balances.
 */
export function MyInvestorSecuritiesPreview({
    broker,
    isImporting,
    onBack,
    onConfirm,
}: {
    broker: MyInvestorSecuritiesImport;
    isImporting: boolean;
    onBack: () => void;
    onConfirm: () => void;
}) {
    const locale = useLocale();
    const rows = investedOrders(broker.file)
        .map(operationRow)
        .sort((a, b) => b.date.localeCompare(a.date));

    return (
        <div className="flex flex-col gap-4">
            <MyInvestorSecuritiesSummaryStrip broker={broker} />

            <div className="overflow-hidden rounded-lg border">
                <div className="max-h-[45vh] overflow-auto">
                    <CompactTransactionTable rows={rows} locale={locale} />
                </div>
                <p className={FOOTNOTE}>
                    {__(
                        'For reference only: this file brings no transactions. Importing it writes what the portfolio was worth each day.',
                    )}
                </p>
            </div>

            <div className="flex items-center justify-between pt-2">
                <Button
                    variant="outline"
                    onClick={onBack}
                    disabled={isImporting}
                >
                    {__('Back')}
                </Button>
                <Button
                    onClick={onConfirm}
                    disabled={isImporting || rows.length === 0}
                >
                    {isImporting
                        ? __('Importing...')
                        : __('Import portfolio history')}
                </Button>
            </div>
        </div>
    );
}
