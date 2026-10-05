import { BankCombobox } from '@/components/accounts/bank-combobox';
import {
    Notice,
    Pill,
    WizardScreen,
} from '@/components/full-import/full-import-layout';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatCount, formatMonthRange } from '@/lib/full-import-format';
import { connectedNamesake } from '@/lib/full-import-plan';
import { formatAccountType, type CurrencyOption } from '@/types/account';
import {
    IMPORT_ACCOUNT_TYPES,
    type AccountPlanEntry,
    type ContextAccount,
    type FileAccount,
    type FullImportMode,
    type ImportAccountAction,
    type ImportAccountType,
} from '@/types/full-import';
import { formatCurrency, toMinorUnits } from '@/utils/currency';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Lock } from 'lucide-react';
import { type ReactNode } from 'react';

interface StepAccountsProps {
    eyebrow: string;
    fileAccounts: FileAccount[];
    plan: Record<string, AccountPlanEntry>;
    onChange: (key: string, entry: AccountPlanEntry) => void;
    mode: FullImportMode;
    accounts: ContextAccount[];
    mappableAccountIds: string[];
    currencies: CurrencyOption[];
    sourceLabel: string;
    /** Changes once the bank guesses arrive, so the pickers show them. */
    banksVersion: string;
    locale: string;
    footer: ReactNode;
}

/** How a file account's fate reads as a pill. */
export function accountActionPill(
    entry: AccountPlanEntry,
    fileAccounts: FileAccount[],
): ReactNode {
    switch (entry.action) {
        case 'map':
            return <Pill>{__('Into your account')}</Pill>;
        case 'merge':
            return (
                <Pill>
                    {__('Merged into :name', {
                        name:
                            fileAccounts.find(
                                (one) => one.key === entry.mergeIntoKey,
                            )?.name ?? '',
                    })}
                </Pill>
            );
        case 'skip':
            return <Pill>{__('Not imported')}</Pill>;
        default:
            return <Pill tone="info">{__('New')}</Pill>;
    }
}

function balanceLine(
    account: FileAccount,
    currency: string,
    locale: string,
): string {
    if (!account.lastBalance) {
        return __('no balances in the file');
    }

    return __(':count daily balances, the last :amount on :date', {
        count: formatCount(account.balanceCount, locale),
        amount: formatCurrency(
            toMinorUnits(account.lastBalance.amount, currency),
            currency,
            locale,
        ),
        date: formatDateMedium(account.lastBalance.date, locale),
    });
}

function Field({
    label,
    htmlFor,
    children,
}: {
    label: string;
    htmlFor?: string;
    children: ReactNode;
}) {
    return (
        <div className="flex min-w-0 flex-col gap-1.5">
            <Label
                htmlFor={htmlFor}
                className="text-[13px] text-muted-foreground"
            >
                {label}
            </Label>
            {children}
        </div>
    );
}

export function StepAccounts({
    eyebrow,
    fileAccounts,
    plan,
    onChange,
    mode,
    accounts,
    mappableAccountIds,
    currencies,
    sourceLabel,
    banksVersion,
    locale,
    footer,
}: StepAccountsProps) {
    const mappable = accounts.filter((account) =>
        mappableAccountIds.includes(account.id),
    );
    const counts = fileAccounts.reduce(
        (tally, account) => {
            tally[plan[account.key].action]++;

            return tally;
        },
        { create: 0, map: 0, merge: 0, skip: 0 } as Record<
            ImportAccountAction,
            number
        >,
    );

    return (
        <WizardScreen
            eyebrow={eyebrow}
            title={__('Your accounts')}
            description={__(
                'There are :count accounts in the file. For each one, choose whether it is new, goes into a manual account you already have, or joins another one from the file.',
                { count: fileAccounts.length },
            )}
            footer={footer}
        >
            <div className="flex flex-wrap gap-2">
                <Pill tone="info">
                    {__(':count new', { count: counts.create })}
                </Pill>
                {counts.map > 0 && (
                    <Pill>
                        {__(':count into your accounts', { count: counts.map })}
                    </Pill>
                )}
                {counts.merge > 0 && (
                    <Pill>{__(':count merged', { count: counts.merge })}</Pill>
                )}
                {counts.skip > 0 && (
                    <Pill>
                        {__(':count not imported', { count: counts.skip })}
                    </Pill>
                )}
            </div>

            <div className="flex flex-col gap-3">
                {fileAccounts.map((account) => (
                    <AccountCard
                        key={account.key}
                        account={account}
                        entry={plan[account.key]}
                        onChange={(entry) => onChange(account.key, entry)}
                        mode={mode}
                        fileAccounts={fileAccounts}
                        plan={plan}
                        mappable={mappable}
                        connected={connectedNamesake(account, { accounts })}
                        currencies={currencies}
                        sourceLabel={sourceLabel}
                        banksVersion={banksVersion}
                        locale={locale}
                    />
                ))}
            </div>
        </WizardScreen>
    );
}

function AccountCard({
    account,
    entry,
    onChange,
    mode,
    fileAccounts,
    plan,
    mappable,
    connected,
    currencies,
    sourceLabel,
    banksVersion,
    locale,
}: {
    account: FileAccount;
    entry: AccountPlanEntry;
    onChange: (entry: AccountPlanEntry) => void;
    mode: FullImportMode;
    fileAccounts: FileAccount[];
    plan: Record<string, AccountPlanEntry>;
    mappable: ContextAccount[];
    connected: ContextAccount | null;
    currencies: CurrencyOption[];
    sourceLabel: string;
    banksVersion: string;
    locale: string;
}) {
    const id = `full-import-account-${account.key}`;
    const mergeTargets = fileAccounts.filter(
        (other) =>
            other.key !== account.key &&
            (plan[other.key].action === 'create' ||
                plan[other.key].action === 'map'),
    );
    const actions: { value: ImportAccountAction; label: string }[] = [
        { value: 'create', label: __('Create new account') },
        ...(mode === 'add' && mappable.length > 0
            ? [
                  {
                      value: 'map' as const,
                      label: __('Add to one of my accounts'),
                  },
              ]
            : []),
        ...(mergeTargets.length > 0
            ? [
                  {
                      value: 'merge' as const,
                      label: __('Merge with another from the file'),
                  },
              ]
            : []),
        { value: 'skip', label: __("Don't import") },
    ];

    const setAction = (action: ImportAccountAction) =>
        onChange({
            ...entry,
            action,
            targetAccountId:
                action === 'map'
                    ? (entry.targetAccountId ?? mappable[0]?.id ?? null)
                    : entry.targetAccountId,
            mergeIntoKey:
                action === 'merge'
                    ? (entry.mergeIntoKey ?? mergeTargets[0]?.key ?? null)
                    : entry.mergeIntoKey,
        });

    return (
        <div className="flex flex-col gap-4 rounded-xl border bg-card p-4 sm:p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 flex-col gap-1">
                    <div className="flex flex-wrap items-center gap-2 font-semibold">
                        {account.name}
                        {accountActionPill(entry, fileAccounts)}
                    </div>
                    <div className="text-[13px] text-muted-foreground">
                        {[
                            __(':count transactions', {
                                count: formatCount(account.count, locale),
                            }),
                            formatMonthRange(account.from, account.to, locale),
                            balanceLine(account, entry.currencyCode, locale),
                        ].join(' · ')}
                    </div>
                </div>
                <Select
                    value={entry.action}
                    onValueChange={(value) =>
                        setAction(value as ImportAccountAction)
                    }
                >
                    <SelectTrigger
                        className="w-full sm:w-60"
                        aria-label={__('What to do with :name', {
                            name: account.name,
                        })}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {actions.map((action) => (
                            <SelectItem key={action.value} value={action.value}>
                                {action.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            {connected && entry.action === 'create' && (
                <Notice icon={Lock}>
                    {__(
                        'Your :name is connected, so nothing is imported into it. A separate manual account gets the :source history.',
                        { name: connected.name, source: sourceLabel },
                    )}
                </Notice>
            )}

            {entry.action === 'create' && (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Field label={__('Name')} htmlFor={`${id}-name`}>
                        <Input
                            id={`${id}-name`}
                            value={entry.name}
                            onChange={(event) =>
                                onChange({ ...entry, name: event.target.value })
                            }
                        />
                    </Field>
                    <Field label={__('Type')}>
                        <Select
                            value={entry.type}
                            onValueChange={(value) =>
                                onChange({
                                    ...entry,
                                    type: value as ImportAccountType,
                                })
                            }
                        >
                            <SelectTrigger aria-label={__('Type')}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {IMPORT_ACCOUNT_TYPES.map((type) => (
                                    <SelectItem key={type} value={type}>
                                        {formatAccountType(type)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field label={__('Currency')}>
                        <Select
                            value={entry.currencyCode}
                            onValueChange={(value) =>
                                onChange({ ...entry, currencyCode: value })
                            }
                        >
                            <SelectTrigger aria-label={__('Currency')}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {currencies.map((currency) => (
                                    <SelectItem
                                        key={currency.code}
                                        value={currency.code}
                                    >
                                        {currency.code}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field label={__('Bank')}>
                        <BankCombobox
                            key={banksVersion}
                            value={entry.bank?.id ?? null}
                            defaultBank={
                                entry.bank
                                    ? { ...entry.bank, user_id: null }
                                    : undefined
                            }
                            onValueChange={() => undefined}
                            onBankChange={(bank) =>
                                onChange({
                                    ...entry,
                                    bank: bank
                                        ? {
                                              id: bank.id,
                                              name: bank.name,
                                              logo: bank.logo,
                                          }
                                        : null,
                                })
                            }
                        />
                    </Field>
                </div>
            )}

            {entry.action === 'map' && (
                <Field label={__('Destination account')}>
                    <Select
                        value={entry.targetAccountId ?? undefined}
                        onValueChange={(value) =>
                            onChange({ ...entry, targetAccountId: value })
                        }
                    >
                        <SelectTrigger
                            className="sm:w-80"
                            aria-label={__('Destination account')}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {mappable.map((target) => (
                                <SelectItem key={target.id} value={target.id}>
                                    {target.name} · {__('manual')}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <span className="text-[13px] text-muted-foreground">
                        {__(
                            'Transactions already in it are skipped, so nothing is duplicated.',
                        )}
                    </span>
                </Field>
            )}

            {entry.action === 'merge' && (
                <Field label={__('Joins')}>
                    <Select
                        value={entry.mergeIntoKey ?? undefined}
                        onValueChange={(value) =>
                            onChange({ ...entry, mergeIntoKey: value })
                        }
                    >
                        <SelectTrigger
                            className="sm:w-80"
                            aria-label={__('Joins')}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {mergeTargets.map((target) => (
                                <SelectItem key={target.key} value={target.key}>
                                    {plan[target.key].action === 'create'
                                        ? plan[target.key].name
                                        : target.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <span className="text-[13px] text-muted-foreground">
                        {__('Its :count transactions go there.', {
                            count: formatCount(account.count, locale),
                        })}
                    </span>
                </Field>
            )}
        </div>
    );
}
