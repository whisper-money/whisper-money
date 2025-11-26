import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
import AuthLayout from '@/layouts/auth-layout';
import {
    bufferToBase64,
    encrypt,
    exportKey,
    generateSalt,
    getAESKeyFromPBKDF,
    getKeyFromPassword,
} from '@/lib/crypto';
import { storeKey } from '@/lib/key-storage';
import { dashboard } from '@/routes';

function isCryptoAvailable(): boolean {
    if (typeof window === 'undefined') return true;
    return !!(window.crypto && window.crypto.subtle);
}

function getInitialErrors(): { password?: string; confirmPassword?: string; general?: string } {
    if (typeof window === 'undefined') return {};
    if (!window.crypto || !window.crypto.subtle) {
        return {
            general: 'Web Crypto API is not available. Please ensure you are accessing this page via HTTPS or localhost.',
        };
    }
    return {};
}

export default function SetupEncryption() {
    const { refreshKeyState } = useEncryptionKey();
    const [password, setPassword] = useState('');
    const [confirmPassword, setConfirmPassword] = useState('');
    const [storagePreference, setStoragePreference] = useState<
        'session' | 'persistent'
    >('session');
    const [processing, setProcessing] = useState(false);
    const [cryptoAvailable] = useState(isCryptoAvailable);
    const [errors, setErrors] = useState<{
        password?: string;
        confirmPassword?: string;
        general?: string;
    }>(getInitialErrors);

    async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setErrors({});

        if (password.length < 12) {
            setErrors({
                password: 'Encryption password must be at least 12 characters',
            });
            return;
        }

        if (password !== confirmPassword) {
            setErrors({
                confirmPassword: 'Passwords do not match',
            });
            return;
        }

        setProcessing(true);

        try {
            const salt = generateSalt();

            const pbkdfKey = await getKeyFromPassword(password);

            const aesKey = await getAESKeyFromPBKDF(pbkdfKey, salt);

            const { encrypted, iv } = await encrypt('Hello, world', aesKey);

            const exportedKey = await exportKey(aesKey);

            await axios.post('/api/encryption/setup', {
                salt: bufferToBase64(salt),
                encrypted_content: encrypted,
                iv: iv,
            });

            storeKey(exportedKey, storagePreference === 'persistent');
            refreshKeyState();

            router.visit(dashboard().url);
        } catch (error) {
            console.error('Encryption setup error:', error);
            setErrors({
                general:
                    'Failed to setup encryption. Please try again or contact support.',
            });
            setProcessing(false);
        }
    }

    return (
        <AuthLayout
            title="Setup Encryption"
            description="Create a strong encryption password to secure your data"
        >
            <Head title="Setup Encryption" />
            <form onSubmit={handleSubmit} className="flex flex-col gap-6">
                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="password">Encryption Password</Label>
                        <Input
                            id="password"
                            type="password"
                            required
                            autoFocus
                            tabIndex={1}
                            autoComplete="new-password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            placeholder="Enter a strong encryption password"
                            disabled={processing}
                        />
                        <p className="text-xs text-muted-foreground">
                            Use a strong password (minimum 12 characters). This
                            password will encrypt your data.
                        </p>
                        <InputError
                            message={errors.password}
                            className="mt-2"
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="confirmPassword">
                            Confirm Password
                        </Label>
                        <Input
                            id="confirmPassword"
                            type="password"
                            required
                            tabIndex={2}
                            autoComplete="new-password"
                            value={confirmPassword}
                            onChange={(e) => setConfirmPassword(e.target.value)}
                            placeholder="Confirm your encryption password"
                            disabled={processing}
                        />
                        <InputError message={errors.confirmPassword} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="storagePreference">
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
                            <SelectTrigger id="storagePreference" tabIndex={3}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="session">
                                    Session only (more secure)
                                </SelectItem>
                                <SelectItem value="persistent">
                                    Keep me logged in (less secure)
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            Session storage clears when you close your browser.
                            Persistent storage keeps your key until manually
                            cleared.
                        </p>
                    </div>

                    {errors.general && (
                        <div className="rounded-md bg-destructive/15 p-3 text-sm text-destructive">
                            {errors.general}
                        </div>
                    )}

                    <Button
                        type="submit"
                        className="mt-2 w-full"
                        tabIndex={4}
                        disabled={processing || !cryptoAvailable}
                    >
                        {processing && <Spinner />}
                        Setup Encryption
                    </Button>
                </div>
            </form>
        </AuthLayout>
    );
}
