import { type UUID } from '@/types/uuid';

/** Which broker export a file is, once recognised. */
export type BrokerFileKind =
    | 'trade_republic'
    | 'myinvestor_cash'
    | 'myinvestor_securities';

/**
 * Every row of a file's first sheet, header and title rows included, cell by
 * cell as the spreadsheet reader returned them.
 */
export type BrokerGrid = unknown[][];

/** What a movement of the cash account is, for the UI to label and categorise. */
export type CashMovementKind =
    | 'card'
    | 'transfer_in'
    | 'transfer_out'
    | 'direct_debit'
    | 'interest'
    | 'dividend'
    | 'saveback'
    | 'bonus'
    | 'buy'
    | 'sell'
    | 'fee'
    | 'tax'
    | 'other';

/** One movement of the cash account, ready to become a transaction. */
export interface CashMovement {
    /** Booking day, `YYYY-MM-DD`. */
    date: string;
    /** Signed, in minor units: what the movement did to the cash balance, fees and taxes included. */
    amount: number;
    currency: string;
    description: string;
    /** Stable across imports of the same file, so a second import is a no-op. */
    externalId: string;
    kind: CashMovementKind;
    isin?: string;
    name?: string;
    /** End-of-row balance, in minor units, when the file carries one. */
    balance?: number;
}

/** One buy or sell of a security, fund or crypto asset. */
export interface Trade {
    /** `YYYY-MM-DD`. */
    date: string;
    side: 'buy' | 'sell';
    /** ISIN for securities and funds, ticker for crypto, the plan code for pension plans. */
    symbol: string;
    name: string;
    /** `STOCK`, `FUND`, `CRYPTO`, `BOND`, `PENSION`… as the file says, or null when it does not. */
    assetClass: string | null;
    /** Always positive: `side` carries the direction. */
    shares: number;
    /** Always positive, in minor units: cash that left (buy) or came back (sell). */
    amount: number;
    currency: string;
    externalId: string;
    /** A leg of a transfer between funds (traspaso, switch): it nets out against the other leg. */
    isTransfer: boolean;
}

/** What is held of one symbol, from the trades in the file. */
export interface Position {
    symbol: string;
    name: string;
    assetClass: string | null;
    shares: number;
    /** Buys minus sells, in minor units. */
    invested: number;
    currency: string;
}

/*
 * What a broker import remembers for the next one, kept on the account the
 * file went to (see broker-memory.ts).
 */

export interface BrokerLinkConfig {
    broker: BrokerFileKind;
    /** Trade Republic only: the account its securities went to, null when skipped. */
    portfolioAccountId?: UUID | null;
    /** When the import ran, as an ISO timestamp. */
    lastImportedAt: string;
    /** The portfolio value the user typed, in minor units, and the day it was for. */
    lastValueMinor?: number | null;
    lastValueDate?: string | null;
    /** What had been invested on the day of the import, in minor units. */
    lastInvestedMinor?: number | null;
}

/** A remembered import, with the account it is kept on. */
export interface BrokerLink {
    accountId: UUID;
    config: BrokerLinkConfig;
}
