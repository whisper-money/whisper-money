import { AmountDisplay } from '@/components/ui/amount-display';
import {
    cashAccountOptions,
    chosenAccountId,
    isBankConnected,
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
    BankConnectedNotice,
    BrokerFileNoticeCard,
    BrokerStepFooter,
    BrokerWarning,
    CashBalanceField,
    firstDay,
    InvestedAndValueFields,
    Panel,
    portfolioChoiceFrom,
    PortfolioSummaryTiles,
    RememberedAccountsBox,
    ReplacesFromNote,
    SummaryTile,
    useMonthRange,
    useRememberedAccounts,
    type RememberedAccount,
} from './import-step-broker-parts';
import { PortfolioOperationsPreview } from './import-step-broker-portfolio-preview';

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
    // The sync brings this account's movements and balance: only the portfolio comes in.
    const cashSynced = isBankConnected(cashAccount);
    const portfolioSkipped = plan.portfolio.kind === 'skip';
    // The sync keeps the cash account's balances: the file replaces none.
    const cashReplacesFrom = cashSynced ? null : firstDay(file.cash);
    const update = (changes: Partial<TradeRepublicPlan>) =>
        onPlanChange({ ...plan, ...changes });
    const { remembered, showPickers, change } = useRememberedAccounts(
        broker,
        cashAccount !== undefined,
    );
    const rememberedPortfolio =
        remembered?.portfolioAccountId &&
        chosenAccountId(plan.portfolio) === remembered.portfolioAccountId
            ? portfolioOptions.find(
                  (account) => account.id === remembered.portfolioAccountId,
              )
            : undefined;
    const rememberedAccounts: RememberedAccount[] = [
        ...(cashAccount ? [{ name: cashAccount.name, role: __('cash') }] : []),
        ...(rememberedPortfolio ? [{ name: rememberedPortfolio.name }] : []),
    ];

    return (
        <div className="flex flex-col gap-4">
            {!showPickers && remembered && (
                <RememberedAccountsBox
                    importedAt={remembered.lastImportedAt}
                    accounts={rememberedAccounts}
                    onChange={change}
                />
            )}
            <div className="grid gap-4 md:grid-cols-2">
                <Panel
                    title={__('Cash')}
                    tag={__(':count movements', { count: file.cash.length })}
                    hint={__(
                        'Card payments, transfers, direct debits, interest and dividends. Buys and sells of securities come out of it too.',
                    )}
                >
                    {showPickers ? (
                        <AccountSelect
                            id="broker-cash-account"
                            value={plan.cash}
                            accounts={cashOptions}
                            createLabel={__('Create “:name” · :type', {
                                name: TRADE_REPUBLIC_CASH_NAME,
                                type: formatAccountType('checking'),
                            })}
                            replacesFrom={cashReplacesFrom}
                            parse={accountChoiceFrom}
                            onChange={(cash) => update({ cash })}
                        />
                    ) : (
                        <ReplacesFromNote replacesFrom={cashReplacesFrom} />
                    )}
                    {!cashSynced && (
                        <CashBalanceField
                            label={__('Cash balance today')}
                            value={plan.balanceToday}
                            currencyCode={currencyCode}
                            hint={__(
                                "Worked out from the file. If it doesn't match the app, correct it and we rebuild each day's balance backwards from it.",
                            )}
                            onChange={(balanceToday) =>
                                update({ balanceToday })
                            }
                        />
                    )}
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
                    {showPickers || !rememberedPortfolio ? (
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
                    ) : (
                        <ReplacesFromNote
                            replacesFrom={firstDay(file.trades)}
                        />
                    )}
                    {!portfolioSkipped && (
                        <InvestedAndValueFields
                            invested={invested}
                            currentValue={plan.currentValue}
                            currencyCode={currencyCode}
                            hint={__(
                                'Copy the total value Trade Republic shows. Leave it empty and we use what was invested until you update it.',
                            )}
                            remembered={remembered}
                            onCurrentValueChange={(currentValue) =>
                                update({ currentValue })
                            }
                        />
                    )}
                </Panel>
            </div>

            {showPickers &&
                cashAccount &&
                !isTradeRepublicAccount(cashAccount) && (
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

            {cashAccount && cashSynced && (
                <BankConnectedNotice>
                    {portfolioSkipped
                        ? __(
                              '“:account” is connected to your bank: its movements and balance come from the sync. Choose an account for the portfolio above, or there is nothing to import.',
                              { account: cashAccount.name },
                          )
                        : __(
                              '“:account” is connected to your bank: its movements and balance come from the sync, so only the portfolio will be imported.',
                              { account: cashAccount.name },
                          )}
                </BankConnectedNotice>
            )}

            <BrokerStepFooter
                onBack={onBack}
                onNext={onNext}
                nextDisabled={
                    cashSynced && (portfolioSkipped || file.trades.length === 0)
                }
            />
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

/**
 * The preview of a Trade Republic file whose cash account is connected to
 * the bank: the sync brings the movements, so there are none to pick, and
 * importing writes what the portfolio was worth each day.
 */
export function TradeRepublicPortfolioPreview({
    broker,
    ...props
}: {
    broker: TradeRepublicImport;
    isImporting: boolean;
    onBack: () => void;
    onConfirm: () => void;
}) {
    const { file, plan } = broker;

    return (
        <PortfolioOperationsPreview
            orders={file.trades}
            summary={
                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <PortfolioSummaryTiles
                        invested={investedToday(file, broker.today)}
                        currentValue={plan.currentValue}
                        currencyCode={tradeRepublicCurrency(file)}
                    />
                </div>
            }
            footnote={__(
                'For reference only: the cash movements come from the bank sync. Importing writes what the portfolio was worth each day.',
            )}
            {...props}
        />
    );
}
