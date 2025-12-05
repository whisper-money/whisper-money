import { update } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { store as storeBank } from '@/actions/App/Http/Controllers/Settings/BankController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { decrypt, encrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import type { Account } from '@/types/account';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AccountForm, AccountFormData } from './account-form';

interface EditAccountDialogProps {
    account: Account;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess?: () => void;
    redirectTo?: string;
}

export function EditAccountDialog({
    account,
    open,
    onOpenChange,
    onSuccess,
    redirectTo,
}: EditAccountDialogProps) {
    const [decryptedName, setDecryptedName] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const formDataRef = useRef<AccountFormData>({
        displayName: '',
        bankId: account.bank.id,
        type: account.type,
        currencyCode: account.currency_code,
        customBank: null,
    });

    useEffect(() => {
        if (!open) return;

        async function decryptName() {
            const keyString = getStoredKey();
            if (!keyString) {
                setDecryptedName('[Encrypted]');
                return;
            }

            try {
                const key = await importKey(keyString);
                const name = await decrypt(account.name, key, account.name_iv);
                setDecryptedName(name);
            } catch (err) {
                console.error('Failed to decrypt account name:', err);
                setDecryptedName('[Encrypted]');
            }
        }

        decryptName();
    }, [open, account.name, account.name_iv]);

    const initialValues = useMemo(
        () =>
            decryptedName && decryptedName !== '[Encrypted]'
                ? {
                      displayName: decryptedName,
                      bank: account.bank,
                      type: account.type,
                      currencyCode: account.currency_code,
                  }
                : undefined,
        [decryptedName, account.bank, account.type, account.currency_code],
    );

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

        try {
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
        } catch (err) {
            console.error('Failed to create bank:', err);
            throw err;
        }
    }

    async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();

        const { displayName, bankId, type, currencyCode, customBank } =
            formDataRef.current;

        const keyString = getStoredKey();
        if (!keyString) {
            alert('Encryption key not available. Please unlock first.');
            return;
        }

        if (!type || !currencyCode) {
            alert('Please fill in all required fields.');
            return;
        }

        setIsSubmitting(true);

        try {
            let finalBankId: string;

            if (customBank) {
                if (!customBank.name.trim()) {
                    alert('Please enter a bank name.');
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
                    alert('Please select a bank.');
                    setIsSubmitting(false);
                    return;
                }
                finalBankId = String(bankId);
            }

            const key = await importKey(keyString);
            const { encrypted, iv } = await encrypt(displayName, key);

            router.patch(
                update.url(account.id),
                {
                    name: encrypted,
                    name_iv: iv,
                    bank_id: finalBankId,
                    type: type,
                    currency_code: currencyCode,
                },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        onOpenChange(false);
                        if (redirectTo) {
                            router.visit(redirectTo);
                        } else {
                            onSuccess?.();
                        }
                    },
                    onFinish: () => {
                        setIsSubmitting(false);
                    },
                },
            );
        } catch (err) {
            console.error('Submission failed:', err);
            alert(
                err instanceof Error
                    ? err.message
                    : 'Failed to update account. Please try again.',
            );
            setIsSubmitting(false);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>Edit Account</DialogTitle>
                    <DialogDescription>
                        Update the account information.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-2">
                    {initialValues ? (
                        <AccountForm
                            initialValues={initialValues}
                            onChange={handleFormChange}
                        />
                    ) : (
                        <div className="space-y-4">
                            <div className="h-10 animate-pulse rounded bg-muted" />
                            <div className="h-10 animate-pulse rounded bg-muted" />
                            <div className="h-10 animate-pulse rounded bg-muted" />
                            <div className="h-10 animate-pulse rounded bg-muted" />
                        </div>
                    )}

                    <div className="flex justify-end gap-2 pt-4">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={isSubmitting}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={isSubmitting || !initialValues}
                        >
                            {isSubmitting ? 'Updating...' : 'Update'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
