import { decrypt, encrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import { evaluateRules } from '@/lib/rule-engine';
import { automationRuleSyncService } from '@/services/automation-rule-sync';
import { transactionSyncService } from '@/services/transaction-sync';
import type { Account, Bank } from '@/types/account';
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
            options?: ReEvaluateAllOptions,
        ) => {
            if (!transactions.length) {
                toast.error('No transactions to re-evaluate');
                return;
            }

            const keyString = getStoredKey();
            if (!keyString) {
                toast.error('Please unlock your encryption key');
                return;
            }

            const key = await importKey(keyString);
            const rules = await automationRuleSyncService.getAll();

            if (!rules.length) {
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

                    const result = evaluateRules(
                        transaction,
                        rules,
                        categories,
                        accounts,
                        banks,
                    );

                    if (result) {
                        let finalNotes = transaction.notes;
                        let finalNotesIv = transaction.notes_iv;

                        if (result.note && result.noteIv) {
                            if (transaction.decryptedNotes) {
                                const combinedNote = `${transaction.decryptedNotes}\n${await decrypt(result.note, key, result.noteIv)}`;
                                const encrypted = await encrypt(
                                    combinedNote,
                                    key,
                                );
                                finalNotes = encrypted.encrypted;
                                finalNotesIv = encrypted.iv;
                            } else {
                                finalNotes = result.note;
                                finalNotesIv = result.noteIv;
                            }
                        }

                        await transactionSyncService.update(transaction.id, {
                            category_id: result.categoryId,
                            notes: finalNotes,
                            notes_iv: finalNotesIv,
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
