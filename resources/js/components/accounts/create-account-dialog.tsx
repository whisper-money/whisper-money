import { useState, useEffect } from 'react';
import { Form } from '@inertiajs/react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
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
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    ACCOUNT_TYPES,
    CURRENCY_OPTIONS,
    formatAccountType,
    type Bank,
} from '@/types/account';
import { store } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { getStoredKey } from '@/lib/key-storage';
import { importKey, encrypt, bufferToBase64 } from '@/lib/crypto';

interface CreateAccountDialogProps {
    banks: Bank[];
}

export function CreateAccountDialog({ banks }: CreateAccountDialogProps) {
    const [open, setOpen] = useState(false);
    const [isKeyAvailable, setIsKeyAvailable] = useState(false);

    useEffect(() => {
        const checkKey = () => {
            const key = getStoredKey();
            setIsKeyAvailable(!!key);
        };

        checkKey();
        const interval = setInterval(checkKey, 1000);

        return () => clearInterval(interval);
    }, []);

    async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();

        const formData = new FormData(event.currentTarget);
        const name = formData.get('name') as string;
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
                'input[name="encrypted_name"]',
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
                <Form
                    {...store.form()}
                    onSubmit={handleSubmit}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="name"
                                id="encrypted_name"
                            />
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
                                <Select name="bank_id" required>
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
                                <Select name="type" required>
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
                                    onClick={() => setOpen(false)}
                                    disabled={processing}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Creating...' : 'Create'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

