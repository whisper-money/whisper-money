import { StepHeader } from '@/components/onboarding/step-header';
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
import {
    bufferToBase64,
    encrypt,
    exportKey,
    generateSalt,
    getAESKeyFromPBKDF,
    getKeyFromPassword,
} from '@/lib/crypto';
import { storeKey } from '@/lib/key-storage';
import axios from 'axios';
import { AlertCircle, CheckCircle2, KeyRound } from 'lucide-react';
import { useState } from 'react';
import { StepButton } from './step-button';

interface StepEncryptionSetupProps {
    onComplete: () => void;
}

export function StepEncryptionSetup({ onComplete }: StepEncryptionSetupProps) {
    const { refreshKeyState } = useEncryptionKey();
    const [password, setPassword] = useState('');
    const [confirmPassword, setConfirmPassword] = useState('');
    const [storagePreference, setStoragePreference] = useState<
        'session' | 'persistent'
    >('session');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const passwordStrength = getPasswordStrength(password);

    async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setError(null);

        if (password.length < 12) {
            setError('Password must be at least 12 characters');
            return;
        }

        if (password !== confirmPassword) {
            setError('Passwords do not match');
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
            onComplete();
        } catch (err) {
            console.error('Encryption setup error:', err);
            setError('Failed to setup encryption. Please try again.');
            setProcessing(false);
        }
    }

    return (
        <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
            <StepHeader
                icon={KeyRound}
                iconContainerClassName="bg-gradient-to-br from-amber-400 to-orange-500"
                title="Create Your Encryption Password"
                description="This password will encrypt all your financial data. Make it strong and memorable — we can't recover it for you."
            />

            <form onSubmit={handleSubmit} className="w-full max-w-md space-y-6">
                <div className="space-y-2">
                    <Label htmlFor="password">Encryption Password</Label>
                    <Input
                        id="password"
                        type="password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        placeholder="Enter a strong password"
                        disabled={processing}
                        autoComplete="new-password"
                        required
                    />
                    <div className="flex items-center gap-2">
                        <div className="flex flex-1 gap-1">
                            {[1, 2, 3, 4].map((level) => (
                                <div
                                    key={level}
                                    className={`h-1.5 flex-1 rounded-full transition-colors ${level <= passwordStrength.level
                                        ? passwordStrength.color
                                        : 'bg-muted'
                                        }`}
                                />
                            ))}
                        </div>
                        <span className="text-xs text-muted-foreground">
                            {passwordStrength.label}
                        </span>
                    </div>
                </div>

                <div className="space-y-2">
                    <Label htmlFor="confirmPassword">Confirm Password</Label>
                    <Input
                        id="confirmPassword"
                        type="password"
                        value={confirmPassword}
                        onChange={(e) => setConfirmPassword(e.target.value)}
                        placeholder="Confirm your password"
                        disabled={processing}
                        autoComplete="new-password"
                        required
                    />
                    {confirmPassword && password === confirmPassword && (
                        <div className="flex items-center gap-1 text-xs text-emerald-600">
                            <CheckCircle2 className="h-3 w-3" />
                            Passwords match
                        </div>
                    )}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="storagePreference">Key Storage</Label>
                    <Select
                        value={storagePreference}
                        onValueChange={(value) =>
                            setStoragePreference(
                                value as 'session' | 'persistent',
                            )
                        }
                        disabled={processing}
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="session">
                                Session only (more secure)
                            </SelectItem>
                            <SelectItem value="persistent">
                                Keep me logged in (convenient)
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p className="text-xs text-muted-foreground">
                        {storagePreference === 'session'
                            ? 'Your key will be cleared when you close the browser.'
                            : 'Your key will be stored until you log out.'}
                    </p>
                </div>

                {error && (
                    <div className="flex items-center gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
                        <AlertCircle className="h-4 w-4 shrink-0" />
                        {error}
                    </div>
                )}

                <StepButton
                    type="submit"
                    disabled={processing || password.length < 12}
                    loading={processing}
                    loadingText="Setting up encryption..."
                    text={
                        'Setup Encryption'
                    }
                />
            </form>
        </div>
    );
}

function getPasswordStrength(password: string): {
    level: number;
    label: string;
    color: string;
} {
    if (!password) {
        return { level: 0, label: '', color: 'bg-muted' };
    }

    let score = 0;

    if (password.length >= 12) score++;
    if (password.length >= 16) score++;
    if (/[A-Z]/.test(password) && /[a-z]/.test(password)) score++;
    if (/\d/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;

    if (score <= 1) {
        return { level: 1, label: 'Weak', color: 'bg-red-500' };
    }
    if (score === 2) {
        return { level: 2, label: 'Fair', color: 'bg-orange-500' };
    }
    if (score === 3) {
        return { level: 3, label: 'Good', color: 'bg-yellow-500' };
    }
    return { level: 4, label: 'Strong', color: 'bg-emerald-500' };
}
