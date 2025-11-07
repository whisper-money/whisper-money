import { useState, useEffect } from 'react';
import { Form, usePage } from '@inertiajs/react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import {
    ACCOUNT_TYPES,
    CURRENCY_OPTIONS,
    formatAccountType,
    type Account,
    type Bank,
} from '@/types/account';
import { update } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { getStoredKey } from '@/lib/key-storage';
import { importKey, encrypt, decrypt, bufferToBase64 } from '@/lib/crypto';

interface EditAccountDialogProps {
    account: Account;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function EditAccountDialog({
    account,
    open,
    onOpenChange,
}: EditAccountDialogProps) {
    const { banks } = usePage<{ banks: Bank[] }>().props;
    const [decryptedName, setDecryptedName] = useState('');

    useEffect(() => {
        async function decryptName() {
            const keyString = getStoredKey();
            if (!keyString) {
                setDecryptedName('[Encrypted]');
                return;
            }

            try {
                const key = await importKey(keyString);
                const name = await decrypt(
                    account.name,
                    key,
                    account.name_iv,
                );
                setDecryptedName(name);
            } catch (err) {
                console.error('Failed to decrypt account name:', err);
                setDecryptedName('[Encrypted]');
            }
        }

        if (open) {
            decryptName();
        }
    }, [open, account]);

    async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();

        const formData = new FormData(event.currentTarget);
        const name = formData.get('display_name') as string;
        const bankId = formData.get('bank_id') as string;
        const type = formData.get('type') as string;
        const currencyCode = formData.get('currency_code') as string;

        const keyString = getStoredKey();
        if (!keyString) {
            alert('Encryption key not available. Please unlock first.');
            return;
        }

        try {
            const key = await importKey(keyString);
            const iv = window.crypto.getRandomValues(new Uint8Array(12));
            const ivString = bufferToBase64(iv);
            const { encrypted } = await encrypt(name, key);

            const form = event.currentTarget;
            const hiddenNameInput = form.querySelector(
                'input[name="name"]',
            ) as HTMLInputElement;
            const hiddenIvInput = form.querySelector(
                'input[name="name_iv"]',
            ) as HTMLInputElement;

            if (hiddenNameInput && hiddenIvInput) {
                hiddenNameInput.value = encrypted;
                hiddenIvInput.value = ivString;
                form.requestSubmit();
            }
        } catch (err) {
            console.error('Encryption failed:', err);
            alert('Failed to encrypt account name. Please try again.');
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
                <Form
                    {...update.form.patch(account.id)}
                    onSubmit={handleSubmit}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <input type="hidden" name="name" id="name" />
                            <input
                                type="hidden"
                                name="name_iv"
                                id="name_iv"
                            />

                            <div className="space-y-2">
                                <Label htmlFor="display_name">Name</Label>
                                <Input
                                    id="display_name"
                                    name="display_name"
                                    defaultValue={decryptedName}
                                    placeholder="Account name"
                                    required
                                />
                                {errors.name && (
                                    <p className="text-sm text-red-500">
                                        {errors.name}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="bank_id">Bank</Label>
                                <Select
                                    name="bank_id"
                                    defaultValue={account.bank.id.toString()}
                                    required
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a bank" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {banks.map((bank) => (
                                            <SelectItem
                                                key={bank.id}
                                                value={bank.id.toString()}
                                            >
                                                <div className="flex items-center gap-2">
                                                    {bank.logo ? (
                                                        <img
                                                            src={bank.logo}
                                                            alt={bank.name}
                                                            className="h-4 w-4 rounded object-contain"
                                                        />
                                                    ) : (
                                                        <div className="h-4 w-4 rounded bg-muted" />
                                                    )}
                                                    <span>{bank.name}</span>
                                                </div>
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.bank_id && (
                                    <p className="text-sm text-red-500">
                                        {errors.bank_id}
                                    </p>
                                )}
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
                                {errors.type && (
                                    <p className="text-sm text-red-500">
                                        {errors.type}
                                    </p>
                                )}
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
                                {errors.currency_code && (
                                    <p className="text-sm text-red-500">
                                        {errors.currency_code}
                                    </p>
                                )}
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                    disabled={processing}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Updating...' : 'Update'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

