import { BankLogo } from '@/components/bank-logo';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Account } from '@/types/account';
import type { BankingConnection, PendingBankAccount } from '@/types/banking';
import { __ } from '@/utils/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Mapping {
    bank_account_uid: string;
    action: 'create' | 'link' | 'skip';
    existing_account_id: string | null;
}

interface Props {
    connection: BankingConnection;
    bankAccounts: PendingBankAccount[];
    existingAccounts: Account[];
}

export default function MapAccountsPage({
    connection,
    bankAccounts,
    existingAccounts,
}: Props) {
    const [mappings, setMappings] = useState<Mapping[]>(
        bankAccounts.map((ba) => ({
            bank_account_uid: ba.uid,
            action: 'create',
            existing_account_id: null,
        })),
    );
    const [processing, setProcessing] = useState(false);

    function updateMapping(uid: string, updates: Partial<Mapping>) {
        setMappings((prev) =>
            prev.map((m) =>
                m.bank_account_uid === uid ? { ...m, ...updates } : m,
            ),
        );
    }

    function getCompatibleAccounts(currency: string) {
        return existingAccounts.filter((a) => a.currency_code === currency);
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        setProcessing(true);
        router.post(
            `/open-banking/connections/${connection.id}/map-accounts`,
            { mappings },
            {
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <div className="flex min-h-svh flex-col items-center justify-center bg-background px-4 py-8">
            <Head title={__('Map Bank Accounts')} />

            <div className="w-full max-w-2xl">
                <div className="mb-6">
                    <h2 className="text-lg font-semibold">
                        {__('Map Bank Accounts')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {__(
                            'Choose how to handle each account from :bank. You can create new accounts, link to existing ones, or skip.',
                            { bank: connection.aspsp_name },
                        )}
                    </p>
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                    {bankAccounts.map((bankAccount) => {
                        const mapping = mappings.find(
                            (m) => m.bank_account_uid === bankAccount.uid,
                        );
                        const compatibleAccounts = getCompatibleAccounts(
                            bankAccount.currency,
                        );
                        const displayName =
                            bankAccount.name ||
                            bankAccount.account_id?.iban ||
                            __('Bank Account');

                        return (
                            <Card key={bankAccount.uid}>
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base">
                                        {displayName}
                                    </CardTitle>
                                    <CardDescription>
                                        {bankAccount.currency}
                                        {bankAccount.account_id?.iban &&
                                            bankAccount.name &&
                                            ` \u00b7 ${bankAccount.account_id.iban}`}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <RadioGroup
                                        value={mapping?.action ?? 'create'}
                                        onValueChange={(
                                            value: Mapping['action'],
                                        ) =>
                                            updateMapping(bankAccount.uid, {
                                                action: value,
                                                existing_account_id:
                                                    value === 'link'
                                                        ? (mapping?.existing_account_id ??
                                                          null)
                                                        : null,
                                            })
                                        }
                                    >
                                        <div className="flex items-center gap-2">
                                            <RadioGroupItem
                                                value="create"
                                                id={`${bankAccount.uid}-create`}
                                            />
                                            <Label
                                                htmlFor={`${bankAccount.uid}-create`}
                                            >
                                                {__('Create new account')}
                                            </Label>
                                        </div>

                                        {compatibleAccounts.length > 0 && (
                                            <div className="space-y-2">
                                                <div className="flex items-center gap-2">
                                                    <RadioGroupItem
                                                        value="link"
                                                        id={`${bankAccount.uid}-link`}
                                                    />
                                                    <Label
                                                        htmlFor={`${bankAccount.uid}-link`}
                                                    >
                                                        {__(
                                                            'Link to existing account',
                                                        )}
                                                    </Label>
                                                </div>

                                                {mapping?.action === 'link' && (
                                                    <div className="ml-6">
                                                        <Select
                                                            value={
                                                                mapping.existing_account_id ??
                                                                undefined
                                                            }
                                                            onValueChange={(
                                                                value,
                                                            ) =>
                                                                updateMapping(
                                                                    bankAccount.uid,
                                                                    {
                                                                        existing_account_id:
                                                                            value,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger>
                                                                <SelectValue
                                                                    placeholder={__(
                                                                        'Select an account',
                                                                    )}
                                                                />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {compatibleAccounts.map(
                                                                    (
                                                                        account,
                                                                    ) => (
                                                                        <SelectItem
                                                                            key={
                                                                                account.id
                                                                            }
                                                                            value={
                                                                                account.id
                                                                            }
                                                                        >
                                                                            <div className="flex items-center gap-2">
                                                                                <BankLogo
                                                                                    src={
                                                                                        account
                                                                                            .bank
                                                                                            ?.logo
                                                                                    }
                                                                                    name={
                                                                                        account
                                                                                            .bank
                                                                                            ?.name
                                                                                    }
                                                                                    className="size-4"
                                                                                    fallback="letter"
                                                                                />
                                                                                {
                                                                                    account.name
                                                                                }
                                                                            </div>
                                                                        </SelectItem>
                                                                    ),
                                                                )}
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                )}
                                            </div>
                                        )}

                                        <div className="flex items-center gap-2">
                                            <RadioGroupItem
                                                value="skip"
                                                id={`${bankAccount.uid}-skip`}
                                            />
                                            <Label
                                                htmlFor={`${bankAccount.uid}-skip`}
                                            >
                                                {__('Skip')}
                                            </Label>
                                        </div>
                                    </RadioGroup>
                                </CardContent>
                            </Card>
                        );
                    })}

                    <div className="flex items-center justify-end gap-3">
                        <Link href="/settings/connections">
                            <Button type="button" variant="outline">
                                {__('Cancel')}
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? __('Saving...') : __('Save & Sync')}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    );
}
