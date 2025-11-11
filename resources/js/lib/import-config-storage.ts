import { type ColumnMapping, DateFormat } from '@/types/import';

interface ImportConfig {
    columnMapping: ColumnMapping;
    dateFormat: DateFormat;
}

const STORAGE_KEY_PREFIX = 'import_config_account_';

export function saveImportConfig(
    accountId: number,
    config: ImportConfig,
): void {
    try {
        const key = `${STORAGE_KEY_PREFIX}${accountId}`;
        localStorage.setItem(key, JSON.stringify(config));
    } catch (error) {
        console.error('Failed to save import configuration:', error);
    }
}

export function loadImportConfig(accountId: number): ImportConfig | null {
    try {
        const key = `${STORAGE_KEY_PREFIX}${accountId}`;
        const stored = localStorage.getItem(key);

        if (!stored) {
            return null;
        }

        const config = JSON.parse(stored) as ImportConfig;

        if (!config.columnMapping || !config.dateFormat) {
            return null;
        }

        return config;
    } catch (error) {
        console.error('Failed to load import configuration:', error);
        return null;
    }
}

export function clearImportConfig(accountId: number): void {
    try {
        const key = `${STORAGE_KEY_PREFIX}${accountId}`;
        localStorage.removeItem(key);
    } catch (error) {
        console.error('Failed to clear import configuration:', error);
    }
}
