import {
    index as listImportConfigs,
    show as showImportConfig,
    update as updateImportConfig,
} from '@/actions/App/Http/Controllers/Api/AccountImportConfigController';
import type {
    BrokerLink,
    BrokerLinkConfig,
} from '@/lib/broker-imports/broker-memory';
import type { BalanceColumnMapping } from '@/types/balance-import';
import { type ColumnMapping, DateFormat } from '@/types/import';
import { type UUID } from '@/types/uuid';
import axios from 'axios';

interface ImportConfig {
    columnMapping: ColumnMapping;
    dateFormat: DateFormat;
    /** The mapping is the AI's suggestion, unchanged. */
    aiSuggested?: boolean;
}

interface BalanceImportConfig {
    columnMapping: BalanceColumnMapping;
    dateFormat: DateFormat;
    /** The mapping is the AI's suggestion, unchanged. */
    aiSuggested?: boolean;
}

type ImportConfigType = 'transaction' | 'balance' | 'broker';

async function saveConfig(
    accountId: UUID,
    type: ImportConfigType,
    config: ImportConfig | BalanceImportConfig | BrokerLinkConfig,
): Promise<void> {
    try {
        await axios.put(updateImportConfig.url(accountId), { type, config });
    } catch (error) {
        console.error(`Failed to save ${type} import configuration:`, error);
    }
}

async function loadConfig<T extends ImportConfig | BalanceImportConfig>(
    accountId: UUID,
    type: ImportConfigType,
): Promise<T | null> {
    try {
        const { data } = await axios.get<{ data: T | null }>(
            showImportConfig.url(accountId, { query: { type } }),
        );

        const config = data.data;

        if (!config || !config.columnMapping || !config.dateFormat) {
            return null;
        }

        return config;
    } catch (error) {
        console.error(`Failed to load ${type} import configuration:`, error);
        return null;
    }
}

export function saveImportConfig(
    accountId: UUID,
    config: ImportConfig,
): Promise<void> {
    return saveConfig(accountId, 'transaction', config);
}

export function loadImportConfig(
    accountId: UUID,
): Promise<ImportConfig | null> {
    return loadConfig<ImportConfig>(accountId, 'transaction');
}

export function saveBalanceImportConfig(
    accountId: UUID,
    config: BalanceImportConfig,
): Promise<void> {
    return saveConfig(accountId, 'balance', config);
}

export function loadBalanceImportConfig(
    accountId: UUID,
): Promise<BalanceImportConfig | null> {
    return loadConfig<BalanceImportConfig>(accountId, 'balance');
}

/** Remembers, on the account a broker file went to, where it went and what it was worth. */
export function saveBrokerLink(
    accountId: UUID,
    config: BrokerLinkConfig,
): Promise<void> {
    return saveConfig(accountId, 'broker', config);
}

/** What every broker import so far remembered, one per account; empty when it cannot be read. */
export async function loadBrokerLinks(): Promise<BrokerLink[]> {
    try {
        const { data } = await axios.get<{
            data: { account_id: UUID; config: BrokerLinkConfig }[];
        }>(listImportConfigs.url({ query: { type: 'broker' } }));

        return data.data.map((entry) => ({
            accountId: entry.account_id,
            config: entry.config,
        }));
    } catch (error) {
        console.error('Failed to load broker import links:', error);
        return [];
    }
}
