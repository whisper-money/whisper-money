import { BankLogo } from '@/components/bank-logo';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    filterTransactionalAccounts,
    formatAccountType,
    type Account,
} from '@/types/account';
import type { UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';

interface ImportStepAccountProps {
    accounts?: Account[];
    selectedAccountId: UUID | null;
    onAccountSelect: (accountId: UUID) => void;
    onNext: () => void;
    /**
     * Go on to the upload with no account, for a broker file whose accounts
     * the import creates. Offered only when given.
     */
    onImportWithoutAccount?: () => void;
}

/** The way on for someone whose broker account is not in the app yet. */
function ImportWithoutAccountLink({ onClick }: { onClick: () => void }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 text-[13px]">
            <span className="text-muted-foreground">
                {__(
                    'Coming from Trade Republic or MyInvestor without an account yet?',
                )}
            </span>
            <Button
                variant="link"
                className="h-auto p-0 text-[13px]"
                onClick={onClick}
            >
                {__("Upload the file and we'll create it")}
            </Button>
        </div>
    );
}

export function ImportStepAccount({
    accounts: rawAccounts = [],
    selectedAccountId,
    onAccountSelect,
    onNext,
    onImportWithoutAccount,
}: ImportStepAccountProps) {
    const accounts = filterTransactionalAccounts(rawAccounts);

    if (accounts.length === 0 && onImportWithoutAccount) {
        return (
            <div className="py-8">
                <ImportWithoutAccountLink onClick={onImportWithoutAccount} />
            </div>
        );
    }

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

                            <BankLogo
                                src={account.bank?.logo ?? null}
                                name={account.bank?.name}
                                className="h-10 w-10"
                                fallback="icon"
                            />
                            <div className="flex flex-1 flex-col gap-1">
                                <span className="font-medium">
                                    {account.name}
                                </span>
                                <span className="text-sm text-muted-foreground">
                                    {account.bank?.name ??
                                        formatAccountType(account.type)}{' '}
                                    • {account.currency_code}
                                </span>
                            </div>
                        </Label>
                    ))}
                </div>
            </RadioGroup>

            {onImportWithoutAccount && (
                <ImportWithoutAccountLink onClick={onImportWithoutAccount} />
            )}

            <div className="flex justify-end">
                <Button onClick={onNext} disabled={!selectedAccountId}>
                    {__('Next')}
                </Button>
            </div>
        </div>
    );
}
