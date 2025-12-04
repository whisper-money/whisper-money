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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { encrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import {
    ACCOUNT_TYPES,
    AccountType,
    CURRENCY_OPTIONS,
    formatAccountType,
} from '@/types/account';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { BankCombobox } from './bank-combobox';
import { CustomBankData, CustomBankForm } from './custom-bank-form';

const initialCustomBankData: CustomBankData = {
    name: '',
    logo: null,
    logoPreview: null,
};

export function CreateAccountDialog({ onSuccess }: { onSuccess?: () => void }) {
    const [open, setOpen] = useState(false);
    const [isKeyAvailable, setIsKeyAvailable] = useState(false);
    const [selectedBankId, setSelectedBankId] = useState<number | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [isCreatingCustomBank, setIsCreatingCustomBank] = useState(false);
    const [customBankData, setCustomBankData] = useState<CustomBankData>(
        initialCustomBankData,
    );
    const [selectedType, setSelectedType] = useState<AccountType | null>(null);

    useEffect(() => {
        const checkKey = () => {
            const key = getStoredKey();
            setIsKeyAvailable(!!key);
        };

        checkKey();
        const interval = setInterval(checkKey, 1000);

        return () => clearInterval(interval);
    }, []);

    const handleCreateCustomBank = useCallback((searchQuery: string) => {
        setIsCreatingCustomBank(true);
        setCustomBankData({
            name: searchQuery,
            logo: null,
            logoPreview: null,
        });
        setSelectedBankId(null);
    }, []);

    const handleCancelCustomBank = useCallback(() => {
        setIsCreatingCustomBank(false);
        setCustomBankData(initialCustomBankData);
    }, []);

    const resetForm = useCallback(() => {
        setSelectedBankId(null);
        setIsCreatingCustomBank(false);
        setCustomBankData(initialCustomBankData);
        setSelectedType(null);
    }, []);

    async function createBankAndGetId(): Promise<string | null> {
        const formData = new FormData();
        formData.append('name', customBankData.name);
        if (customBankData.logo) {
            formData.append('logo', customBankData.logo);
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

        const formData = new FormData(event.currentTarget);
        const displayName = formData.get('display_name') as string;
        const type = formData.get('type') as string;
        const currencyCode = formData.get('currency_code') as string;

        const keyString = getStoredKey();
        if (!keyString) {
            alert('Encryption key not available. Please unlock first.');
            return;
        }

        setIsSubmitting(true);

        try {
            let bankId: string;

            if (isCreatingCustomBank) {
                if (!customBankData.name.trim()) {
                    alert('Please enter a bank name.');
                    setIsSubmitting(false);
                    return;
                }
                const createdBankId = await createBankAndGetId();
                if (!createdBankId) {
                    throw new Error('Failed to create bank');
                }
                bankId = createdBankId;
            } else {
                bankId = formData.get('bank_id') as string;
                if (!bankId) {
                    alert('Please select a bank.');
                    setIsSubmitting(false);
                    return;
                }
            }

            const key = await importKey(keyString);
            const { encrypted, iv } = await encrypt(displayName, key);

            router.post(
                store.url(),
                {
                    name: encrypted,
                    name_iv: iv,
                    bank_id: bankId,
                    type: type,
                    currency_code: currencyCode,
                },
                {
                    onSuccess: () => {
                        setOpen(false);
                        resetForm();
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
                <form onSubmit={handleSubmit} className="space-y-4">
                    <>
                        <div className="space-y-2">
                            <Label htmlFor="display_name">Name</Label>
                            <Input
                                id="display_name"
                                name="display_name"
                                className="mt-1"
                                placeholder="Account name"
                                required
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="bank_id">Bank</Label>
                            {isCreatingCustomBank ? (
                                <CustomBankForm
                                    defaultName={customBankData.name}
                                    value={customBankData}
                                    onChange={setCustomBankData}
                                    onCancel={handleCancelCustomBank}
                                />
                            ) : (
                                <>
                                    <input
                                        type="hidden"
                                        name="bank_id"
                                        value={selectedBankId || ''}
                                        required
                                    />
                                    <BankCombobox
                                        value={selectedBankId}
                                        onValueChange={setSelectedBankId}
                                        onCreateCustomBank={
                                            handleCreateCustomBank
                                        }
                                    />
                                </>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="type">Account Type</Label>
                            <div className="mt-1">
                                <Select
                                    name="type"
                                    value={selectedType ?? undefined}
                                    onValueChange={(value) =>
                                        setSelectedType(value as AccountType)
                                    }
                                    required
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select account type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {ACCOUNT_TYPES.map((type) => (
                                            <SelectItem key={type} value={type}>
                                                {formatAccountType(type)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {(selectedType === 'investment' ||
                                    selectedType === 'retirement') && (
                                        <p className="pl-1 text-xs text-muted-foreground">
                                            This account type is for balance
                                            tracking only and doesn't support
                                            transactions.
                                        </p>
                                    )}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="currency_code">Currency</Label>
                            <div className="mt-1">
                                <Select name="currency_code" required>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select currency" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CURRENCY_OPTIONS.map((currency) => (
                                            <SelectItem
                                                key={currency}
                                                value={currency}
                                            >
                                                {currency}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="flex justify-end gap-2">
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
                    </>
                </form>
            </DialogContent>
        </Dialog>
    );
}
