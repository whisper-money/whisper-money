import { AccountName } from '@/components/accounts/account-name';
import { BankLogo } from '@/components/bank-logo';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { type Account } from '@/types/account';
import type { UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';

interface ImportBalanceStepAccountProps {
    accounts?: Account[];
    selectedAccountId: UUID | null;
    onAccountSelect: (accountId: UUID) => void;
    onNext: () => void;
}

export function ImportBalanceStepAccount({
    accounts = [],
    selectedAccountId,
    onAccountSelect,
    onNext,
}: ImportBalanceStepAccountProps) {
    if (accounts.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-8 text-center">
                <p className="text-sm text-muted-foreground">
                    {__('No accounts found. Please create an account first.')}
                </p>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-6">
            <RadioGroup
                value={selectedAccountId ?? undefined}
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

                            <BankLogo
                                src={account.bank?.logo ?? null}
                                name={account.bank?.name}
                                className="h-10 w-10"
                                fallback="icon"
                            />
                            <div className="flex flex-1 flex-col gap-1">
                                <span className="font-medium">
                                    <AccountName
                                        account={account}
                                        length={19}
                                    />
                                </span>
                                <span className="text-sm text-muted-foreground">
                                    {account.bank?.name ??
                                        account.currency_code}{' '}
                                    • {account.currency_code}
                                </span>
                            </div>
                        </Label>
                    ))}
                </div>
            </RadioGroup>

            <div className="flex justify-end">
                <Button onClick={onNext} disabled={!selectedAccountId}>
                    {__('Next')}
                </Button>
            </div>
        </div>
    );
}
