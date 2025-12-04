import { store } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { store as storeBank } from '@/actions/App/Http/Controllers/Settings/BankController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { encrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { AccountForm, AccountFormData } from './account-form';

export function CreateAccountDialog({ onSuccess }: { onSuccess?: () => void }) {
    const [open, setOpen] = useState(false);
    const [isKeyAvailable, setIsKeyAvailable] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const formDataRef = useRef<AccountFormData>({
        displayName: '',
        bankId: null,
        type: null,
        currencyCode: null,
        customBank: null,
    });

    useEffect(() => {
        const checkKey = () => {
            const key = getStoredKey();
            setIsKeyAvailable(!!key);
        };

        checkKey();
        const interval = setInterval(checkKey, 1000);

        return () => clearInterval(interval);
    }, []);

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
                        setOpen(false);
                        onSuccess?.();
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
                    : 'Failed to create account. Please try again.',
            );
            setIsSubmitting(false);
        }
    }

    const createButton = (
        <Button disabled={!isKeyAvailable}>Create Account</Button>
    );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {isKeyAvailable ? (
                    createButton
                ) : (
                    <TooltipProvider>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                {createButton}
                            </TooltipTrigger>
                            <TooltipContent>
                                <p>
                                    Encryption key required. Please unlock your
                                    data first.
                                </p>
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                )}
            </DialogTrigger>
            <DialogContent className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>Create Account</DialogTitle>
                    <DialogDescription>
                        Add a new bank account to track your transactions.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-2">
                    <AccountForm onChange={handleFormChange} />

                    <div className="flex justify-end gap-2 pt-4">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                            disabled={isSubmitting}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={isSubmitting}>
                            {isSubmitting ? 'Creating...' : 'Create'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
