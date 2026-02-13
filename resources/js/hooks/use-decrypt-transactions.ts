import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { decrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useRef } from 'react';

interface EncryptedTransaction {
    id: string;
    description: string;
    description_iv: string | null;
    notes: string | null;
    notes_iv: string | null;
}

interface PaginatedResponse {
    data: EncryptedTransaction[];
    next_page_url: string | null;
}

interface BulkUpdateItem {
    id: string;
    description?: string;
    notes?: string | null;
    description_iv: null;
    notes_iv: null;
}

export function useDecryptTransactions() {
    const { isKeySet } = useEncryptionKey();
    const { hasEncryptedTransactions } = usePage<SharedData>().props;
    const hasRun = useRef(false);

    useEffect(() => {
        if (!isKeySet || !hasEncryptedTransactions || hasRun.current) {
            return;
        }

        hasRun.current = true;

        async function migrateTransactions() {
            try {
                const keyString = getStoredKey();
                if (!keyString) {
                    return;
                }

                const key = await importKey(keyString);

                let url: string | null = '/api/transactions?encrypted=true';

                while (url) {
                    const { data: page } =
                        await axios.get<PaginatedResponse>(url);

                    const batch: BulkUpdateItem[] = [];

                    for (const transaction of page.data) {
                        try {
                            const item: BulkUpdateItem = {
                                id: transaction.id,
                                description_iv: null,
                                notes_iv: null,
                            };

                            if (transaction.description_iv) {
                                item.description = await decrypt(
                                    transaction.description,
                                    key,
                                    transaction.description_iv,
                                );
                            }

                            if (transaction.notes_iv && transaction.notes) {
                                item.notes = await decrypt(
                                    transaction.notes,
                                    key,
                                    transaction.notes_iv,
                                );
                            }

                            batch.push(item);
                        } catch {
                            // Skip transactions that fail to decrypt
                        }
                    }

                    if (batch.length > 0) {
                        // Send in chunks of 50
                        for (let i = 0; i < batch.length; i += 50) {
                            const chunk = batch.slice(i, i + 50);
                            await axios.patch('/api/transactions/bulk', {
                                transactions: chunk,
                            });
                        }
                    }

                    url = page.next_page_url;
                }

                window.location.reload();
            } catch {
                // Silent failure — migration will retry next session
                hasRun.current = false;
            }
        }

        migrateTransactions();
    }, [isKeySet, hasEncryptedTransactions]);
}
