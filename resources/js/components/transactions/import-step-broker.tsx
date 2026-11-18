import {
    unknownBroker,
    type BrokerImport,
} from '@/lib/broker-imports/broker-import';
import { type Account } from '@/types/account';
import { __ } from '@/utils/i18n';
import {
    ImportStepBrokerMyInvestorCash,
    ImportStepBrokerMyInvestorSecurities,
    MyInvestorCashFileNotice,
    MyInvestorCashSummaryStrip,
    MyInvestorSecuritiesFileNotice,
    MyInvestorSecuritiesSummaryStrip,
} from './import-step-broker-myinvestor';
import {
    ImportStepBrokerTradeRepublic,
    TradeRepublicFileNotice,
    TradeRepublicSummaryStrip,
} from './import-step-broker-trade-republic';

/*
 * The broker's own pieces of the import drawer. The drawer hands over the
 * recognised file and these pick what that broker shows; a new broker adds a
 * case to each.
 */

interface ImportStepBrokerProps {
    broker: BrokerImport;
    accounts: Account[];
    onBrokerChange: (broker: BrokerImport) => void;
    onNext: () => void;
    onBack: () => void;
}

/** The step that replaces the column mapping for a broker file. */
export function ImportStepBroker({
    broker,
    onBrokerChange,
    ...props
}: ImportStepBrokerProps) {
    switch (broker.kind) {
        case 'trade_republic':
            return (
                <ImportStepBrokerTradeRepublic
                    broker={broker}
                    onPlanChange={(plan) => onBrokerChange({ ...broker, plan })}
                    {...props}
                />
            );
        case 'myinvestor_cash':
            return (
                <ImportStepBrokerMyInvestorCash
                    broker={broker}
                    onPlanChange={(plan) => onBrokerChange({ ...broker, plan })}
                    {...props}
                />
            );
        case 'myinvestor_securities':
            return (
                <ImportStepBrokerMyInvestorSecurities
                    broker={broker}
                    onPlanChange={(plan) => onBrokerChange({ ...broker, plan })}
                    {...props}
                />
            );
        default:
            return unknownBroker(broker);
    }
}

/** What the upload step says under a recognised file. */
export function BrokerFileNotice({ broker }: { broker: BrokerImport }) {
    switch (broker.kind) {
        case 'trade_republic':
            return <TradeRepublicFileNotice broker={broker} />;
        case 'myinvestor_cash':
            return <MyInvestorCashFileNotice broker={broker} />;
        case 'myinvestor_securities':
            return <MyInvestorSecuritiesFileNotice broker={broker} />;
        default:
            return unknownBroker(broker);
    }
}

/** The strip the preview opens with for a broker file. */
export function BrokerImportSummary({ broker }: { broker: BrokerImport }) {
    switch (broker.kind) {
        case 'trade_republic':
            return <TradeRepublicSummaryStrip broker={broker} />;
        case 'myinvestor_cash':
            return <MyInvestorCashSummaryStrip broker={broker} />;
        case 'myinvestor_securities':
            return <MyInvestorSecuritiesSummaryStrip broker={broker} />;
        default:
            return unknownBroker(broker);
    }
}

/** The broker step's heading. */
export function brokerStepInfo(broker: BrokerImport): {
    title: string;
    description: string;
} {
    switch (broker.kind) {
        case 'trade_republic':
            return {
                title: __('Trade Republic accounts'),
                description: __(
                    'The file carries cash and securities: we keep them in two accounts',
                ),
            };
        case 'myinvestor_cash':
            return {
                title: __('MyInvestor current account'),
                description: __(
                    'We keep its movements and each day’s balance in one account',
                ),
            };
        case 'myinvestor_securities':
            return {
                title: __('MyInvestor portfolio'),
                description: __(
                    'We keep what was invested day by day in an investment account',
                ),
            };
        default:
            return unknownBroker(broker);
    }
}
