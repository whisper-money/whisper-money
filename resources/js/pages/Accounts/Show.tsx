import { index, show } from '@/actions/App/Http/Controllers/AccountController';
import { AccountBalanceChart } from '@/components/accounts/account-balance-chart';
import { EncryptedText } from '@/components/encrypted-text';
import HeadingSmall from '@/components/heading-small';
import { TransactionList } from '@/components/transactions/transaction-list';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { BreadcrumbItem } from '@/types';
import { Account, Bank, formatAccountType } from '@/types/account';
import { Category } from '@/types/category';
import { Head } from '@inertiajs/react';

interface Props {
    account: Account;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
}

export default function AccountShow({
    account,
    categories,
    accounts,
    banks,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Accounts',
            href: index().url,
        },
        {
            title: (
                <EncryptedText
                    encryptedText={account.name}
                    iv={account.name_iv}
                    length={{ min: 5, max: 20 }}
                />
            ),
            href: show.url(account.id),
        },
    ];

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title="Account Details" />

            <div className="space-y-6 p-6">
                <div className="flex items-center gap-4 pl-1">
                    {account.bank?.logo ? (
                        <img
                            src={account.bank.logo}
                            alt={account.bank.name}
                            className="size-12 rounded-full object-contain"
                        />
                    ) : (
                        <div className="flex size-12 items-center justify-center rounded-full bg-muted">
                            <span className="text-lg font-medium text-muted-foreground">
                                {account.bank?.name?.charAt(0) || '?'}
                            </span>
                        </div>
                    )}
                    <HeadingSmall
                        title={
                            <EncryptedText
                                encryptedText={account.name}
                                iv={account.name_iv}
                                length={{ min: 8, max: 30 }}
                            />
                        }
                        description={`${account.bank?.name || 'Unknown Bank'} · ${formatAccountType(account.type)}`}
                    />
                </div>

                <AccountBalanceChart account={account} />

                <div className="space-y-4">
                    <h2 className="text-lg font-semibold">Transactions</h2>
                    <TransactionList
                        categories={categories}
                        accounts={accounts}
                        banks={banks}
                        accountId={account.id}
                        pageSize={10}
                        hideAccountFilter={true}
                        showActionsMenu={false}
                    />
                </div>
            </div>
        </AppSidebarLayout>
    );
}
