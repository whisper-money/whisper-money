import { SyncManager } from '@/lib/sync-manager';
import type { AutomationRule } from '@/types/automation-rule';
import type { UUID } from '@/types/uuid';

class AutomationRuleSyncService {
    private syncManager: SyncManager;

    constructor() {
        this.syncManager = new SyncManager({
            storeName: 'automation_rules',
            endpoint: '/api/sync/automation-rules',
        });
    }

    async sync() {
        return await this.syncManager.sync();
    }

    async getAll(): Promise<AutomationRule[]> {
        const rules = await this.syncManager.getAll<AutomationRule>();
        return rules.map((rule) => ({
            ...rule,
            rules_json:
                typeof rule.rules_json === 'string'
                    ? JSON.parse(rule.rules_json)
                    : rule.rules_json,
        }));
    }

    async getById(id: UUID): Promise<AutomationRule | null> {
        const rule = await this.syncManager.getById<AutomationRule>(id);
        if (!rule) {
            return null;
        }
        return {
            ...rule,
            rules_json:
                typeof rule.rules_json === 'string'
                    ? JSON.parse(rule.rules_json)
                    : rule.rules_json,
        };
    }

    async create(data: Omit<AutomationRule, 'id'>): Promise<AutomationRule> {
        return await this.syncManager.createLocal<AutomationRule>(
            data as Omit<AutomationRule, 'id'> & {
                id?: UUID;
                created_at?: string;
                updated_at?: string;
            },
        );
    }

    async update(id: UUID, data: Partial<AutomationRule>): Promise<void> {
        await this.syncManager.updateLocal<AutomationRule>(id, data);
    }

    async delete(id: UUID): Promise<void> {
        await this.syncManager.deleteLocal(id);
    }

    async getLastSyncTime(): Promise<string | null> {
        return await this.syncManager.getLastSyncTime();
    }

    isSyncing(): boolean {
        return this.syncManager.isSyncing();
    }
}

export const automationRuleSyncService = new AutomationRuleSyncService();
