import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';
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
} from '@/types/account';
import { store } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { getStoredKey } from '@/lib/key-storage';
import { importKey, encrypt } from '@/lib/crypto';
import { BankCombobox } from './bank-combobox';

export function CreateAccountDialog() {
    const [open, setOpen] = useState(false);
    const [isKeyAvailable, setIsKeyAvailable] = useState(false);
    const [selectedBankId, setSelectedBankId] = useState<number | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

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

            router.post(store.url(), {
                name: encrypted,
                name_iv: iv,
                bank_id: bankId,
                type: type,
                currency_code: currencyCode,
            }, {
                onSuccess: () => {
                    setOpen(false);
                },
                onFinish: () => {
                    setIsSubmitting(false);
                },
            });
        } catch (err) {
            console.error('Encryption failed:', err);
            alert('Failed to encrypt account name. Please try again.');
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
                <form
                    onSubmit={handleSubmit}
                    className="space-y-4"
                >
                    <>

                        <div className="space-y-2">
                            <Label htmlFor="display_name">Name</Label>
                            <Input
                                id="display_name"
                                name="display_name"
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
                            />
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

