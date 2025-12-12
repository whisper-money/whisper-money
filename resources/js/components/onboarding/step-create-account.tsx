import { store } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { store as storeBank } from '@/actions/App/Http/Controllers/Settings/BankController';
import {
    AccountForm,
    AccountFormData,
} from '@/components/accounts/account-form';
import { StepHeader } from '@/components/onboarding/step-header';
import { Button } from '@/components/ui/button';
import { CreatedAccount } from '@/hooks/use-onboarding-state';
import { encrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import { type AccountType, formatAccountType } from '@/types/account';
import { AlertCircle, CheckCircle2, CreditCard } from 'lucide-react';
import { useCallback, useMemo, useRef, useState } from 'react';
import { StepButton } from './step-button';

interface ExistingAccount {
    id: string;
    name: string;
    name_iv: string;
    type: string;
    currency_code: string;
    bank_id: string;
    bank?: {
        id: string;
        name: string;
        logo: string | null;
    };
}

interface StepCreateAccountProps {
    banks: { id: string; name: string; logo: string | null }[];
    isFirstAccount: boolean;
    existingAccounts?: ExistingAccount[];
    onAccountCreated: (account: CreatedAccount) => void;
    onSkip?: () => void;
}

export function StepCreateAccount({
    isFirstAccount,
    existingAccounts = [],
    onAccountCreated,
    onSkip,
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

            const response = await fetch(store.url(), {
                method: 'POST',
                body: JSON.stringify({
                    name: encrypted,
                    name_iv: iv,
                    bank_id: finalBankId,
                    type: type,
                    currency_code: currencyCode,
                }),
                headers: {
                    'Content-Type': 'application/json',
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
                throw new Error(
                    errorData.message ||
                        Object.values(errorData.errors || {})[0] ||
                        'Failed to create account',
                );
            }

            const accountData = await response.json();

            onAccountCreated({
                id: accountData.id || finalBankId,
                name: displayName,
                type: type,
                currencyCode: currencyCode,
            });
            setIsSubmitting(false);
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

    const hasExistingAccounts = existingAccounts.length > 0;

    const { title, description } = useMemo(() => {
        if (hasExistingAccounts) {
            return {
                title: 'Your Accounts',
                description:
                    "You already have accounts set up. Let's continue with the onboarding.",
            };
        }
        if (isFirstAccount) {
            return {
                title: 'Create an Account',
                description:
                    "Let's start with your main checking account. You can add more accounts later.",
            };
        }
        return {
            title: 'Add Another Account',
            description: 'Add another account to track more of your finances.',
        };
    }, [hasExistingAccounts, isFirstAccount]);

    return (
        <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
            <StepHeader
                icon={CreditCard}
                iconContainerClassName="bg-gradient-to-br from-emerald-400 to-teal-500"
                title={title}
                description={description}
            />

            {hasExistingAccounts ? (
                <div className="w-full max-w-md">
                    <div className="mb-6 space-y-2">
                        {existingAccounts.map((account) => (
                            <div
                                key={account.id}
                                className="flex items-center gap-3 rounded-lg border bg-card p-4"
                            >
                                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                                    <CheckCircle2 className="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                                </div>
                                <div className="flex-1">
                                    <p className="font-medium">
                                        {account.bank?.name || 'Account'}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {formatAccountType(
                                            account.type as AccountType,
                                        )}{' '}
                                        • {account.currency_code}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>

                    <StepButton
                        text="Continue"
                        onClick={() =>
                            onAccountCreated({
                                id: existingAccounts[0].id,
                                name:
                                    existingAccounts[0].bank?.name || 'Account',
                                type: existingAccounts[0].type,
                                currencyCode: existingAccounts[0].currency_code,
                            })
                        }
                    />
                </div>
            ) : (
                <form
                    onSubmit={handleSubmit}
                    autoFocus
                    className="w-full max-w-md space-y-4"
                >
                    <AccountForm
                        forceAccountType={
                            isFirstAccount ? 'checking' : undefined
                        }
                        onChange={handleFormChange}
                    />

                    {isFirstAccount && (
                        <div className="rounded-lg border border-blue-100 bg-blue-50 p-3 text-sm dark:border-blue-900/50 dark:bg-blue-900/20">
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

                    <StepButton
                        type="submit"
                        disabled={isSubmitting}
                        loading={isSubmitting}
                        loadingText="Creating..."
                        text="Create Account"
                    />

                    {!isFirstAccount && onSkip && (
                        <Button
                            size="lg"
                            className="w-full opacity-50 transition-all duration-200 hover:opacity-100"
                            variant={'ghost'}
                            disabled={isSubmitting}
                            onClick={() => onSkip()}
                        >
                            Ignore
                        </Button>
                    )}
                </form>
            )}
        </div>
    );
}
