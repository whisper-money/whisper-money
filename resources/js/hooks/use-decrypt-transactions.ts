import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { decrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useRef } from 'react';

// on 429 wait out the API throttle and retry the same request,
// instead of aborting the whole migration. Honours Laravel's Retry-After header.
async function withRetry<T>(fn: () => Promise<T>): Promise<T> {
    while (true) {
        try {
            return await fn();
        } catch (e) {
            if (axios.isAxiosError(e) && e.response?.status === 429) {
                const retryAfter =
                    Number(e.response.headers['retry-after']) || 1;
                await new Promise((resolve) =>
                    setTimeout(resolve, retryAfter * 1000),
                );

                continue;
            }

            throw e;
        }
    }
}

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

                // Always re-fetch the first page: each bulk update clears the
                // rows' IVs, removing them from the encrypted set, so an
                // offset-based cursor (next_page_url) would skip the rows that
                // shift into the freed slots. Stop when nothing is left, or
                // when a page yields nothing we can decrypt — otherwise rows
                // that always fail to decrypt would loop forever.
                while (true) {
                    const { data: page } = await withRetry(() =>
                        axios.get<PaginatedResponse>(
                            '/api/transactions?encrypted=true',
                        ),
                    );

                    if (page.data.length === 0) {
                        break;
                    }

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

                    if (batch.length === 0) {
                        break;
                    }

                    // Send in chunks of 50
                    for (let i = 0; i < batch.length; i += 50) {
                        const chunk = batch.slice(i, i + 50);
                        await withRetry(() =>
                            axios.patch('/api/transactions/bulk', {
                                transactions: chunk,
                            }),
                        );
                    }
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
