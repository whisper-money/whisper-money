import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import {
    base64ToBuffer,
    decrypt,
    exportKey,
    getAESKeyFromPBKDF,
    getKeyFromPassword,
    importKey,
} from '@/lib/crypto';
import { getStoredKey, storeKey } from '@/lib/key-storage';

interface UnlockMessageDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onUnlock: (message: string) => void;
    encryptedContent: string;
    iv: string;
    salt: string;
}

export default function UnlockMessageDialog({
    open,
    onOpenChange,
    onUnlock,
    encryptedContent,
    iv,
    salt,
}: UnlockMessageDialogProps) {
    const { refreshKeyState } = useEncryptionKey();
    const [password, setPassword] = useState('');
    const [storagePreference, setStoragePreference] = useState<
        'session' | 'persistent'
    >('session');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) {
            setPassword('');
            setError(null);
        }
    }, [open]);

    async function handleUnlock(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setError(null);
        setProcessing(true);

        try {
            const storedKey = getStoredKey();

            let aesKey: CryptoKey;

            if (storedKey) {
                aesKey = await importKey(storedKey);
            } else {
                const saltBuffer = base64ToBuffer(salt);
                const pbkdfKey = await getKeyFromPassword(password);
                aesKey = await getAESKeyFromPBKDF(pbkdfKey, saltBuffer);
            }

            const decrypted = await decrypt(encryptedContent, aesKey, iv);

            if (!storedKey && password) {
                const exportedKey = await exportKey(aesKey);
                storeKey(exportedKey, storagePreference === 'persistent');
                refreshKeyState();
            }

            onUnlock(decrypted);
            setPassword('');
            setError(null);
            onOpenChange(false);
        } catch (err) {
            console.error('Decryption error:', err);
            setError(
                'Failed to decrypt message. Please check your password and try again.',
            );
        } finally {
            setProcessing(false);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent hasKeyboard>
                <DialogHeader>
                    <DialogTitle>Unlock Encrypted Message</DialogTitle>
                    <DialogDescription>
                        Enter your encryption password to decrypt and view your
                        message.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleUnlock}>
                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label htmlFor="unlock-password">
                                Encryption Password
                            </Label>
                            <Input
                                id="unlock-password"
                                type="password"
                                required
                                autoFocus
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                placeholder="Enter your encryption password"
                                disabled={processing}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="unlock-storage">
                                Storage Preference
                            </Label>
                            <Select
                                value={storagePreference}
                                onValueChange={(value) =>
                                    setStoragePreference(
                                        value as 'session' | 'persistent',
                                    )
                                }
                                disabled={processing}
                            >
                                <SelectTrigger id="unlock-storage">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="session">
                                        Session only
                                    </SelectItem>
                                    <SelectItem value="persistent">
                                        Keep me logged in
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {error && (
                            <div className="rounded-md bg-destructive/15 p-3 text-sm text-destructive">
                                {error}
                            </div>
                        )}
                    </div>
                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Unlock
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
