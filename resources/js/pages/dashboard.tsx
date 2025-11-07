import { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';

import UnlockMessageDialog from '@/components/unlock-message-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/app-layout';
import { base64ToBuffer, decrypt, importKey } from '@/lib/crypto';
import { clearKey, getStoredKey } from '@/lib/key-storage';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

interface EncryptedMessageData {
    encrypted_content: string;
    iv: string;
    salt: string;
}

export default function Dashboard() {
    const [messageData, setMessageData] = useState<EncryptedMessageData | null>(
        null
    );
    const [decryptedMessage, setDecryptedMessage] = useState<string | null>(
        null
    );
    const [showUnlockDialog, setShowUnlockDialog] = useState(false);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        fetchAndDecryptMessage();
    }, []);

    async function fetchAndDecryptMessage() {
        setLoading(true);
        setError(null);

        try {
            const response = await axios.get<EncryptedMessageData>(
                '/api/encryption/message'
            );
            const data = response.data;
            setMessageData(data);

            const storedKey = getStoredKey();

            if (storedKey) {
                try {
                    const aesKey = await importKey(storedKey);
                    const decrypted = await decrypt(
                        data.encrypted_content,
                        aesKey,
                        data.iv
                    );
                    setDecryptedMessage(decrypted);
                } catch (err) {
                    console.error('Auto-decrypt failed:', err);
                    clearKey();
                }
            }
        } catch (err: any) {
            if (err.response?.status === 404) {
                setError('No encrypted message found. Please set up encryption first.');
            } else {
                console.error('Fetch error:', err);
                setError('Failed to load encrypted message.');
            }
        } finally {
            setLoading(false);
        }
    }

    function handleUnlock(message: string) {
        setDecryptedMessage(message);
    }

    function handleLock() {
        clearKey();
        setDecryptedMessage(null);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="grid auto-rows-min gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>Encrypted Message</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {loading ? (
                                <div className="space-y-2">
                                    <Skeleton className="h-4 w-full" />
                                    <Skeleton className="h-4 w-3/4" />
                                </div>
                            ) : error ? (
                                <div className="text-sm text-destructive">
                                    {error}
                                </div>
                            ) : decryptedMessage ? (
                                <div className="space-y-4">
                                    <p className="text-lg font-medium text-green-600 dark:text-green-400">
                                        {decryptedMessage}
                                    </p>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={handleLock}
                                    >
                                        Lock Message
                                    </Button>
                                </div>
                            ) : messageData ? (
                                <div className="space-y-4">
                                    <p className="break-all text-sm font-mono text-muted-foreground">
                                        {messageData.encrypted_content.substring(
                                            0,
                                            100
                                        )}
                                        ...
                                    </p>
                                    <Button
                                        onClick={() =>
                                            setShowUnlockDialog(true)
                                        }
                                    >
                                        Unlock Message
                                    </Button>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>

                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                </div>
                <div className="relative min-h-[100vh] flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border">
                    <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                </div>
            </div>

            {messageData && (
                <UnlockMessageDialog
                    open={showUnlockDialog}
                    onOpenChange={setShowUnlockDialog}
                    onUnlock={handleUnlock}
                    encryptedContent={messageData.encrypted_content}
                    iv={messageData.iv}
                    salt={messageData.salt}
                />
            )}
        </AppLayout>
    );
}
