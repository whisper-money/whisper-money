<?php

namespace App\Enums;

/**
 * The broker exports the import drawer reads on its own, as the client's
 * `BrokerFileKind` (resources/js/lib/broker-imports/types.ts) names them.
 */
enum BrokerImportKind: string
{
    case TradeRepublic = 'trade_republic';
    case MyInvestorCash = 'myinvestor_cash';
    case MyInvestorSecurities = 'myinvestor_securities';
}
