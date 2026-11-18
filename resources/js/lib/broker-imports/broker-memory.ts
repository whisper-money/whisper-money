import {
    cashAccountOptions,
    chosenAccountId,
    portfolioAccountOptions,
} from '@/lib/broker-imports/broker-accounts';
import {
    unknownBroker,
    type BrokerAccountRole,
    type BrokerImport,
} from '@/lib/broker-imports/broker-import';
import { myInvestorInvestedToday } from '@/lib/broker-imports/myinvestor-import';
import { investedToday } from '@/lib/broker-imports/trade-republic-import';
import { type BrokerFileKind } from '@/lib/broker-imports/types';
import { saveBrokerLink } from '@/lib/import-config-storage';
import { type Account } from '@/types/account';
import { type UUID } from '@/types/uuid';

/*
 * What a broker import remembers for the next one: kept on the account the
 * file went to, it says which accounts that was and what the portfolio was
 * worth, so a re-import picks the same accounts even when the user renamed
 * them, and shows how things moved since.
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

/** Which of an import's accounts its link is kept on: the one the file went to. */
const LINK_ROLE: Record<BrokerFileKind, BrokerAccountRole> = {
    trade_republic: 'cash',
    myinvestor_cash: 'cash',
    myinvestor_securities: 'portfolio',
};

function linkOptions(kind: BrokerFileKind, accounts: Account[]): Account[] {
    return LINK_ROLE[kind] === 'cash'
        ? cashAccountOptions(accounts)
        : portfolioAccountOptions(accounts);
}

/** The account the plan picks for the role the link is kept on. */
function linkedAccountId(broker: BrokerImport): UUID | null {
    switch (broker.kind) {
        case 'trade_republic':
        case 'myinvestor_cash':
            return chosenAccountId(broker.plan.cash);
        case 'myinvestor_securities':
            return chosenAccountId(broker.plan.portfolio);
        default:
            return unknownBroker(broker);
    }
}

function latest(links: BrokerLink[]): BrokerLink | null {
    return (
        [...links].sort((a, b) =>
            b.config.lastImportedAt.localeCompare(a.config.lastImportedAt),
        )[0] ?? null
    );
}

/**
 * The remembered import a new one of this broker goes by. An account picked
 * on the first step only goes by its own; otherwise the one on the account
 * the heuristic found, else the latest. Only links on accounts that are
 * still there and not archived count.
 */
export function rememberedLink(
    kind: BrokerFileKind,
    links: BrokerLink[],
    accounts: Account[],
    selectedAccountId: UUID | null,
    heuristicAccountId: UUID | null,
): BrokerLink | null {
    const options = linkOptions(kind, accounts);
    const usable = links.filter(
        (link) =>
            link.config.broker === kind &&
            options.some((account) => account.id === link.accountId),
    );

    if (
        LINK_ROLE[kind] === 'cash' &&
        options.some((account) => account.id === selectedAccountId)
    ) {
        return (
            usable.find((link) => link.accountId === selectedAccountId) ?? null
        );
    }

    return (
        usable.find((link) => link.accountId === heuristicAccountId) ??
        latest(usable)
    );
}

/**
 * The import with the accounts it went to last time, when there was one:
 * those take the place of the heuristic's picks, and a remembered portfolio
 * that is gone leaves the heuristic's in place.
 */
export function withRememberedAccounts(
    broker: BrokerImport,
    links: BrokerLink[],
    accounts: Account[],
    selectedAccountId: UUID | null,
): BrokerImport {
    const link = rememberedLink(
        broker.kind,
        links,
        accounts,
        selectedAccountId,
        linkedAccountId(broker),
    );

    if (!link) {
        return broker;
    }

    const linked = { kind: 'existing', accountId: link.accountId } as const;

    switch (broker.kind) {
        case 'trade_republic': {
            const portfolioId = link.config.portfolioAccountId;
            const portfolioExists = portfolioAccountOptions(accounts).some(
                (account) => account.id === portfolioId,
            );

            return {
                ...broker,
                memory: link,
                plan: {
                    ...broker.plan,
                    cash: linked,
                    portfolio:
                        portfolioId && portfolioExists
                            ? { kind: 'existing', accountId: portfolioId }
                            : broker.plan.portfolio,
                },
            };
        }
        case 'myinvestor_cash':
            return {
                ...broker,
                memory: link,
                plan: { ...broker.plan, cash: linked },
            };
        case 'myinvestor_securities':
            return {
                ...broker,
                memory: link,
                plan: { ...broker.plan, portfolio: linked },
            };
        default:
            return unknownBroker(broker);
    }
}

/**
 * The remembered import, while the plan still sends the file to the account
 * it is kept on: once the user picks another, last time no longer applies.
 */
export function brokerMemoryInUse(broker: BrokerImport): BrokerLink | null {
    const memory = broker.memory ?? null;

    return memory && linkedAccountId(broker) === memory.accountId
        ? memory
        : null;
}

/** What the portfolio held and is worth today, for an import that has one. */
function portfolioToday(
    broker: BrokerImport,
): { invested: number; currentValue: number | null } | null {
    switch (broker.kind) {
        case 'trade_republic':
            return broker.plan.portfolio.kind === 'skip'
                ? null
                : {
                      invested: investedToday(broker.file, broker.today),
                      currentValue: broker.plan.currentValue,
                  };
        case 'myinvestor_cash':
            return null;
        case 'myinvestor_securities':
            return {
                invested: myInvestorInvestedToday(broker.file, broker.today),
                currentValue: broker.plan.currentValue,
            };
        default:
            return unknownBroker(broker);
    }
}

/**
 * What an import just done leaves for the next one, on the account the file
 * went to. A value left empty this time keeps the one typed before.
 */
export function brokerLinkAfterImport(
    broker: BrokerImport,
    accounts: Partial<Record<BrokerAccountRole, Account>>,
    now: Date,
): BrokerLink | null {
    const account = accounts[LINK_ROLE[broker.kind]];

    if (!account) {
        return null;
    }

    const previous =
        broker.memory?.accountId === account.id ? broker.memory.config : null;
    const portfolio = portfolioToday(broker);
    const typedValue = portfolio?.currentValue ?? null;

    return {
        accountId: account.id,
        config: {
            broker: broker.kind,
            ...(broker.kind === 'trade_republic'
                ? { portfolioAccountId: accounts.portfolio?.id ?? null }
                : {}),
            lastImportedAt: now.toISOString(),
            lastInvestedMinor:
                portfolio?.invested ?? previous?.lastInvestedMinor ?? null,
            lastValueMinor: typedValue ?? previous?.lastValueMinor ?? null,
            lastValueDate:
                typedValue !== null
                    ? broker.today
                    : (previous?.lastValueDate ?? null),
        },
    };
}

/** Keeps what the next import of this broker needs; a failure only costs the memory. */
export async function rememberBrokerImport(
    broker: BrokerImport,
    accounts: Partial<Record<BrokerAccountRole, Account>>,
): Promise<void> {
    const link = brokerLinkAfterImport(broker, accounts, new Date());

    if (link) {
        await saveBrokerLink(link.accountId, link.config);
    }
}
