import { index as importDataRoute } from '@/actions/App/Http/Controllers/Api/ImportDataController';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { __ } from '@/utils/i18n';
import { useState } from 'react';
import { toast } from 'sonner';

export interface TransactionDialogData {
    accounts: Account[];
    categories: Category[];
    banks: Bank[];
    labels: Label[];
    automationRules: AutomationRule[];
}

/**
 * The lists the add and import dialogs need, fetched the first time one is
 * opened rather than shipped with the page: both live in the app chrome, and
 * most visits never press either.
 */
export function useTransactionDialogData() {
    const [data, setData] = useState<TransactionDialogData | null>(null);
    const [loading, setLoading] = useState(false);

    /** Resolves to whether the data is there, so the caller knows to open. */
    async function load(): Promise<boolean> {
        setLoading(true);
        try {
            const response = await fetch(importDataRoute.url());
            if (!response.ok) {
                throw new Error('Failed to load import data');
            }
            setData(await response.json());

            return true;
        } catch (error) {
            toast.error(__('Failed to load import data'));
            console.error(error);

            return false;
        } finally {
            setLoading(false);
        }
    }

    return { data, loading, load };
}
