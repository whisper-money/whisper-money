import { EncryptedText } from '@/components/encrypted-text';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { accountSyncService } from '@/services/account-sync';
import { filterTransactionalAccounts, type Account } from '@/types/account';
import type { UUID } from '@/types/uuid';
import { Building2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface ImportStepAccountProps {
    selectedAccountId: UUID | null;
    onAccountSelect: (accountId: UUID) => void;
    onNext: () => void;
}

export function ImportStepAccount({
    selectedAccountId,
    onAccountSelect,
    onNext,
}: ImportStepAccountProps) {
    const [accounts, setAccounts] = useState<Account[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const loadAccounts = async () => {
            try {
                const data = await accountSyncService.getAll();
                setAccounts(filterTransactionalAccounts(data));
            } catch (error) {
                console.error('Failed to load accounts:', error);
            } finally {
                setLoading(false);
            }
        };

        loadAccounts();
    }, []);

    // If there is only one account, auto-select it, and proceed to next step
    useEffect(() => {
        if (!loading && accounts.length === 1) {
            onAccountSelect(accounts[0].id);
            onNext();
        }
    }, [loading, accounts, onAccountSelect, onNext]);

    if (loading) {
        return (
            <div className="flex items-center justify-center py-8">
                <p className="text-sm text-muted-foreground">
                    Loading accounts...
                </p>
            </div>
        );
    }

    if (accounts.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-8 text-center">
                <p className="text-sm text-muted-foreground">
                    No accounts found. Please create an account first.
                </p>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-6">
            <RadioGroup
                value={selectedAccountId}
                onValueChange={(value) => onAccountSelect(value)}
            >
                <div className="space-y-3">
                    {accounts.map((account) => (
                        <Label
                            htmlFor={`account-${account.id}`}
                            key={account.id}
                            className="flex items-center space-x-3 rounded-lg border p-4 hover:bg-accent"
                        >
                            <RadioGroupItem
                                value={account.id}
                                id={`account-${account.id}`}
                            />
                            {account.bank.logo ? (
                                <img
                                    src={account.bank.logo}
                                    alt={account.bank.name}
                                    className="h-10 w-10 rounded-md object-contain"
                                />
                            ) : (
                                <div className="flex h-10 w-10 items-center justify-center rounded-md bg-muted">
                                    <Building2 className="h-5 w-5 text-muted-foreground" />
                                </div>
                            )}
                            <div className="flex flex-1 flex-col gap-1">
                                <span className="font-medium">
                                    <EncryptedText
                                        encryptedText={account.name}
                                        iv={account.name_iv}
                                        length={19}
                                    />
                                </span>
                                <span className="text-sm text-muted-foreground">
                                    {account.bank.name} •{' '}
                                    {account.currency_code}
                                </span>
                            </div>
                        </Label>
                    ))}
                </div>
            </RadioGroup>

            <div className="flex justify-end">
                <Button onClick={onNext} disabled={!selectedAccountId}>
                    Next
                </Button>
            </div>
        </div>
    );
}
