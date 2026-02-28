import { evaluateRules } from '@/lib/rule-engine';
import { transactionSyncService } from '@/services/transaction-sync';
import type { Account, Bank } from '@/types/account';
import type { AutomationRule } from '@/types/automation-rule';
import type { Category } from '@/types/category';
import type { DecryptedTransaction } from '@/types/transaction';
import { useCallback } from 'react';
import { toast } from 'sonner';

interface ReEvaluateAllOptions {
    onProgress?: (progress: {
        current: number;
        total: number;
        transactionId: string;
        description: string;
    }) => void;
}

export function useReEvaluateAllTransactions() {
    const reEvaluateAll = useCallback(
        async (
            transactions: DecryptedTransaction[],
            categories: Category[],
            accounts: Account[],
            banks: Bank[],
            automationRules: AutomationRule[],
            options?: ReEvaluateAllOptions,
        ) => {
            if (!transactions.length) {
                toast.error('No transactions to re-evaluate');
                return;
            }

            if (!automationRules.length) {
                toast.error('No automation rules found');
                return;
            }

            const toastId = toast.loading(
                `Re-evaluating 0 of ${transactions.length} transactions...`,
            );

            let successCount = 0;

            try {
                for (let i = 0; i < transactions.length; i++) {
                    const transaction = transactions[i];
                    const progress = i + 1;

                    options?.onProgress?.({
                        current: progress,
                        total: transactions.length,
                        transactionId: transaction.id,
                        description: transaction.decryptedDescription,
                    });

                    const result = await evaluateRules(
                        transaction,
                        automationRules,
                        categories,
                        accounts,
                        banks,
                        null,
                    );

                    if (result) {
                        await transactionSyncService.update(transaction.id, {
                            category_id: result.categoryId,
                            notes: transaction.notes,
                            notes_iv: transaction.notes_iv,
                        });

                        successCount++;
                    }

                    toast.loading(
                        `Re-evaluating ${progress} of ${transactions.length} transactions...`,
                        { id: toastId },
                    );
                }

                toast.dismiss(toastId);
                toast.success(() => (
                    <div>
                        {`Re-evaluation complete!`}
                        <br />
                        {`${successCount} transaction(s) updated.`}
                    </div>
                ));
            } catch (error) {
                console.error('Failed to re-evaluate transactions:', error);
                toast.error(
                    'Failed to re-evaluate transactions. Please try again.',
                    { id: toastId },
                );
                throw error;
            }
        },
        [],
    );

    return { reEvaluateAll };
}
