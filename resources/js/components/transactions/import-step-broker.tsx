import { type BrokerImport } from '@/lib/broker-imports/broker-import';
import { type Account } from '@/types/account';
import { __ } from '@/utils/i18n';
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
    today: string;
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
    }
}

/** What the upload step says under a recognised file. */
export function BrokerFileNotice({ broker }: { broker: BrokerImport }) {
    switch (broker.kind) {
        case 'trade_republic':
            return <TradeRepublicFileNotice broker={broker} />;
    }
}

/** The strip the preview opens with for a broker file. */
export function BrokerImportSummary({
    broker,
    today,
}: {
    broker: BrokerImport;
    today: string;
}) {
    switch (broker.kind) {
        case 'trade_republic':
            return <TradeRepublicSummaryStrip broker={broker} today={today} />;
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
    }
}
