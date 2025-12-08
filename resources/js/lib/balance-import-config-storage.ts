import type { BalanceColumnMapping } from '@/types/balance-import';
import { DateFormat } from '@/types/import';
import type { UUID } from '@/types/uuid';

interface BalanceImportConfig {
    columnMapping: BalanceColumnMapping;
    dateFormat: DateFormat;
}

const STORAGE_KEY_PREFIX = 'balance_import_config_account_';

export function saveBalanceImportConfig(
    accountId: UUID,
    config: BalanceImportConfig,
): void {
    if (typeof window === 'undefined') return;

    try {
        const key = `${STORAGE_KEY_PREFIX}${accountId}`;
        localStorage.setItem(key, JSON.stringify(config));
    } catch (error) {
        console.error('Failed to save balance import configuration:', error);
    }
}

export function loadBalanceImportConfig(
    accountId: UUID,
): BalanceImportConfig | null {
    if (typeof window === 'undefined') return null;

    try {
        const key = `${STORAGE_KEY_PREFIX}${accountId}`;
        const stored = localStorage.getItem(key);

        if (!stored) {
            return null;
        }

        const config = JSON.parse(stored) as BalanceImportConfig;

        if (!config.columnMapping || !config.dateFormat) {
            return null;
        }

        return config;
    } catch (error) {
        console.error('Failed to load balance import configuration:', error);
        return null;
    }
}

export function clearBalanceImportConfig(accountId: UUID): void {
    if (typeof window === 'undefined') return;

    try {
        const key = `${STORAGE_KEY_PREFIX}${accountId}`;
        localStorage.removeItem(key);
    } catch (error) {
        console.error('Failed to clear balance import configuration:', error);
    }
}
