import { store } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { store as storeBank } from '@/actions/App/Http/Controllers/Settings/BankController';
import {
    AccountForm,
    AccountFormData,
} from '@/components/accounts/account-form';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { CreatedAccount } from '@/hooks/use-onboarding-state';
import { encrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import { router } from '@inertiajs/react';
import { AlertCircle, CreditCard } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';

interface StepCreateAccountProps {
    banks: { id: string; name: string; logo: string | null }[];
    isFirstAccount: boolean;
    onAccountCreated: (account: CreatedAccount) => void;
}

export function StepCreateAccount({
    isFirstAccount,
    onAccountCreated,
}: StepCreateAccountProps) {
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const formDataRef = useRef<AccountFormData>({
        displayName: '',
        bankId: null,
        type: isFirstAccount ? 'checking' : null,
        currencyCode: null,
        customBank: null,
    });

    const handleFormChange = useCallback((data: AccountFormData) => {
        formDataRef.current = data;
    }, []);

    async function createBankAndGetId(): Promise<string | null> {
        const customBank = formDataRef.current.customBank;
        if (!customBank) return null;

        const formData = new FormData();
        formData.append('name', customBank.name);
        if (customBank.logo) {
            formData.append('logo', customBank.logo);
        }

        const response = await fetch(storeBank.url(), {
            method: 'POST',
            body: formData,
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie
                        .split('; ')
                        .find((row) => row.startsWith('XSRF-TOKEN='))
                        ?.split('=')[1] || '',
                ),
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message || 'Failed to create bank');
        }

        const data = await response.json();
        return data.id;
    }

    async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError(null);

        const { displayName, bankId, type, currencyCode, customBank } =
            formDataRef.current;

        const keyString = getStoredKey();
        if (!keyString) {
            setError(
                'Encryption key not available. Please go back and set up encryption.',
            );
            return;
        }

        if (!displayName.trim()) {
            setError('Please enter an account name.');
            return;
        }

        if (!type || !currencyCode) {
            setError('Please fill in all required fields.');
            return;
        }

        if (isFirstAccount && type !== 'checking') {
            setError('Your first account must be a checking account.');
            return;
        }

        setIsSubmitting(true);

        try {
            let finalBankId: string;

            if (customBank) {
                if (!customBank.name.trim()) {
                    setError('Please enter a bank name.');
                    setIsSubmitting(false);
                    return;
                }
                const createdBankId = await createBankAndGetId();
                if (!createdBankId) {
                    throw new Error('Failed to create bank');
                }
                finalBankId = createdBankId;
            } else {
                if (!bankId) {
                    setError('Please select a bank.');
                    setIsSubmitting(false);
                    return;
                }
                finalBankId = String(bankId);
            }

            const key = await importKey(keyString);
            const { encrypted, iv } = await encrypt(displayName, key);

            router.post(
                store.url(),
                {
                    name: encrypted,
                    name_iv: iv,
                    bank_id: finalBankId,
                    type: type,
                    currency_code: currencyCode,
                },
                {
                    onSuccess: () => {
                        onAccountCreated({
                            id: finalBankId,
                            name: displayName,
                            type: type,
                            currencyCode: currencyCode,
                        });
                    },
                    onError: (errors) => {
                        setError(
                            (Object.values(errors)[0] as string) ||
                            'Failed to create account',
                        );
                        setIsSubmitting(false);
                    },
                    onFinish: () => {
                        setIsSubmitting(false);
                    },
                },
            );
        } catch (err) {
            console.error('Account creation failed:', err);
            setError(
                err instanceof Error
                    ? err.message
                    : 'Failed to create account. Please try again.',
            );
            setIsSubmitting(false);
        }
    }

    return (
        <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
            <div className="mb-8 flex h-20 w-20 animate-in items-center justify-center rounded-full bg-gradient-to-br from-emerald-400 to-teal-500 shadow-lg duration-500 zoom-in">
                <CreditCard className="h-10 w-10 text-white" />
            </div>

            <h1 className="mb-4 text-center text-3xl font-bold tracking-tight">
                {isFirstAccount
                    ? 'Create Your First Account'
                    : 'Add Another Account'}
            </h1>

            <p className="mb-8 max-w-md text-center text-muted-foreground">
                {isFirstAccount
                    ? "Let's start with your main checking account. You can add more accounts later."
                    : 'Add another account to track more of your finances.'}
            </p>

            <form onSubmit={handleSubmit} autoFocus className="w-full max-w-md space-y-4">
                <AccountForm forceAccountType={isFirstAccount ? 'checking' : undefined} onChange={handleFormChange} />

                {isFirstAccount && (
                    <div className="rounded-lg border border-blue-100 bg-blue-50 text-sm p-3 dark:border-blue-900/50 dark:bg-blue-900/20">
                        <p className="text-center">
                            Your first account must be a{' '}
                            <strong>Checking</strong> account.
                        </p>
                    </div>
                )}

                {error && (
                    <div className="flex items-center gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
                        <AlertCircle className="h-4 w-4 shrink-0" />
                        {error}
                    </div>
                )}

                <Button
                    type="submit"
                    size="lg"
                    className="w-full"
                    disabled={isSubmitting}
                >
                    {isSubmitting && <Spinner className="mr-2" />}
                    {isSubmitting ? 'Creating...' : 'Create Account'}
                </Button>
            </form>
        </div>
    );
}
