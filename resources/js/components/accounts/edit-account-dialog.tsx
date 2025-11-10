import { update } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
import { decrypt, encrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import {
    ACCOUNT_TYPES,
    CURRENCY_OPTIONS,
    formatAccountType,
    type Account,
} from '@/types/account';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { BankCombobox } from './bank-combobox';

interface EditAccountDialogProps {
    account: Account;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess?: () => void;
}

export function EditAccountDialog({
    account,
    open,
    onOpenChange,
    onSuccess,
}: EditAccountDialogProps) {
    const [decryptedName, setDecryptedName] = useState('');
    const [selectedBankId, setSelectedBankId] = useState<number | null>(
        account.bank.id,
    );
    const [isSubmitting, setIsSubmitting] = useState(false);

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

    // Update selected bank when dialog opens with a new account
    useEffect(() => {
        if (open && selectedBankId !== account.bank.id) {
            // Schedule state update to avoid cascading renders
            const timer = setTimeout(() => {
                setSelectedBankId(account.bank.id);
            }, 0);
            return () => clearTimeout(timer);
        }
    }, [open, account.bank.id, selectedBankId]);

    async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();

        const formData = new FormData(event.currentTarget);
        const displayName = formData.get('display_name') as string;
        const bankId = formData.get('bank_id') as string;
        const type = formData.get('type') as string;
        const currencyCode = formData.get('currency_code') as string;

        const keyString = getStoredKey();
        if (!keyString) {
            alert('Encryption key not available. Please unlock first.');
            return;
        }

        setIsSubmitting(true);

        try {
            const key = await importKey(keyString);
            const { encrypted, iv } = await encrypt(displayName, key);

            router.patch(
                update.url(account.id),
                {
                    name: encrypted,
                    name_iv: iv,
                    bank_id: bankId,
                    type: type,
                    currency_code: currencyCode,
                },
                {
                    onSuccess: () => {
                        onOpenChange(false);
                        onSuccess?.();
                    },
                    onFinish: () => {
                        setIsSubmitting(false);
                    },
                },
            );
        } catch (err) {
            console.error('Encryption failed:', err);
            alert('Failed to encrypt account name. Please try again.');
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
                <form onSubmit={handleSubmit} className="space-y-4">
                    <>
                        <div className="space-y-2">
                            <Label htmlFor="display_name">Name</Label>
                            <Input
                                id="display_name"
                                name="display_name"
                                defaultValue={decryptedName}
                                placeholder="Account name"
                                required
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="bank_id">Bank</Label>
                            <input
                                type="hidden"
                                name="bank_id"
                                value={selectedBankId || ''}
                                required
                            />
                            <BankCombobox
                                value={selectedBankId}
                                onValueChange={setSelectedBankId}
                                defaultBank={account.bank}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="type">Account Type</Label>
                            <Select
                                name="type"
                                defaultValue={account.type}
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
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="currency_code">Currency</Label>
                            <Select
                                name="currency_code"
                                defaultValue={account.currency_code}
                                required
                            >
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

                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => onOpenChange(false)}
                                disabled={isSubmitting}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={isSubmitting}>
                                {isSubmitting ? 'Updating...' : 'Update'}
                            </Button>
                        </div>
                    </>
                </form>
            </DialogContent>
        </Dialog>
    );
}
