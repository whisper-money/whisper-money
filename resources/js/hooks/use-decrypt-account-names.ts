import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { decrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useRef } from 'react';

interface EncryptedAccount {
    id: string;
    name: string;
    name_iv: string | null;
    encrypted: boolean;
}

export function useDecryptAccountNames() {
    const { isKeySet } = useEncryptionKey();
    const { hasEncryptedAccounts } = usePage<SharedData>().props;
    const hasRun = useRef(false);

    useEffect(() => {
        if (!isKeySet || !hasEncryptedAccounts || hasRun.current) {
            return;
        }

        hasRun.current = true;

        async function migrateAccounts() {
            try {
                const keyString = getStoredKey();
                if (!keyString) {
                    return;
                }

                const { data: accounts } =
                    await axios.get<EncryptedAccount[]>('/api/accounts');

                const encryptedAccounts = accounts.filter(
                    (a) => a.encrypted && a.name_iv,
                );

                if (encryptedAccounts.length === 0) {
                    return;
                }

                const key = await importKey(keyString);

                for (const account of encryptedAccounts) {
                    try {
                        const decryptedName = await decrypt(
                            account.name,
                            key,
                            account.name_iv!,
                        );

                        await axios.put(`/api/accounts/${account.id}`, {
                            name: decryptedName,
                            encrypted: false,
                        });
                    } catch {
                        // Skip accounts that fail to decrypt
                    }
                }

                window.location.reload();
            } catch {
                // Silent failure — migration will retry next session
                hasRun.current = false;
            }
        }

        migrateAccounts();
    }, [isKeySet, hasEncryptedAccounts]);
}
