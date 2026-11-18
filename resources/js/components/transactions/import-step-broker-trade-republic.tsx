import { AmountDisplay } from '@/components/ui/amount-display';
import {
    cashAccountOptions,
    portfolioAccountOptions,
} from '@/lib/broker-imports/broker-accounts';
import { brokerCashAccountId } from '@/lib/broker-imports/broker-import';
import {
    investedToday,
    isTradeRepublicAccount,
    TRADE_REPUBLIC_CASH_NAME,
    TRADE_REPUBLIC_PORTFOLIO_NAME,
    tradeRepublicCurrency,
    tradeRepublicSummary,
    type TradeRepublicImport,
    type TradeRepublicPlan,
} from '@/lib/broker-imports/trade-republic-import';
import { formatAccountType, type Account } from '@/types/account';
import { __ } from '@/utils/i18n';
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
    portfolioChoiceFrom,
    PortfolioSummaryTiles,
    SummaryTile,
    useMonthRange,
} from './import-step-broker-parts';

interface ImportStepBrokerTradeRepublicProps {
    broker: TradeRepublicImport;
    accounts: Account[];
    onPlanChange: (plan: TradeRepublicPlan) => void;
    onNext: () => void;
    onBack: () => void;
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
                    <CashBalanceField
                        label={__('Cash balance today')}
                        value={plan.balanceToday}
                        currencyCode={currencyCode}
                        hint={__(
                            "Worked out from the file. If it doesn't match the app, correct it and we rebuild each day's balance backwards from it.",
                        )}
                        onChange={(balanceToday) => update({ balanceToday })}
                    />
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
                        <InvestedAndValueFields
                            invested={invested}
                            currentValue={plan.currentValue}
                            currencyCode={currencyCode}
                            hint={__(
                                'Copy the total value Trade Republic shows. Leave it empty and we use what was invested until you update it.',
                            )}
                            onCurrentValueChange={(currentValue) =>
                                update({ currentValue })
                            }
                        />
                    )}
                </Panel>
            </div>

            {cashAccount && !isTradeRepublicAccount(cashAccount) && (
                <BrokerWarning>
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
                </BrokerWarning>
            )}

            <BrokerStepFooter onBack={onBack} onNext={onNext} />
        </div>
    );
}

/** What the upload step says under a Trade Republic file. */
export function TradeRepublicFileNotice({
    broker,
}: {
    broker: TradeRepublicImport;
}) {
    const { movements, from, to } = tradeRepublicSummary(broker.file);
    const range = useMonthRange(from, to);

    return (
        <BrokerFileNoticeCard
            badge="TR"
            title={__('This is a Trade Republic export')}
        >
            {__(
                ":count movements, :range. No column mapping needed: you'll choose the accounts next.",
                { count: movements, range },
            )}
        </BrokerFileNoticeCard>
    );
}

/** The strip above the preview: where the two accounts will stand once imported. */
export function TradeRepublicSummaryStrip({
    broker,
}: {
    broker: TradeRepublicImport;
}) {
    const { file, plan } = broker;
    const currencyCode = tradeRepublicCurrency(file);

    return (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <SummaryTile label={__('Cash today')}>
                <AmountDisplay
                    amountInCents={plan.balanceToday}
                    currencyCode={currencyCode}
                />
            </SummaryTile>
            {plan.portfolio.kind !== 'skip' && (
                <PortfolioSummaryTiles
                    invested={investedToday(file, broker.today)}
                    currentValue={plan.currentValue}
                    currencyCode={currencyCode}
                />
            )}
        </div>
    );
}
