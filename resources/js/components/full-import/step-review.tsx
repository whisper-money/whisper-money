import {
    Notice,
    SectionCard,
    SectionRow,
    StatCard,
    WizardScreen,
} from '@/components/full-import/full-import-layout';
import { accountActionPill } from '@/components/full-import/step-accounts';
import { joinNames } from '@/components/full-import/step-existing';
import { Checkbox } from '@/components/ui/checkbox';
import { formatCount } from '@/lib/full-import-format';
import { accountPayloadKey, type BuiltImport } from '@/lib/full-import-plan';
import {
    type AccountPlanEntry,
    type ContextAccount,
    type FileAccount,
    type FullImportMode,
} from '@/types/full-import';
import { __ } from '@/utils/i18n';
import { Sparkles } from 'lucide-react';
import { type ReactNode } from 'react';

interface StepReviewProps {
    eyebrow: string;
    mode: FullImportMode;
    fileAccounts: FileAccount[];
    plan: Record<string, AccountPlanEntry>;
    accounts: ContextAccount[];
    built: BuiltImport;
    unreadable: number;
    uncategorized: number;
    aiNote: string | null;
    confirmWipe: boolean;
    onConfirmWipeChange: (confirmed: boolean) => void;
    locale: string;
    footer: ReactNode;
}

/** What the review says about the movements nobody will import. */
function skippedNote(
    skippedAccounts: number,
    unreadable: number,
    locale: string,
): string {
    const parts = [
        skippedAccounts > 0 &&
            __(':count from accounts you are not importing', {
                count: formatCount(skippedAccounts, locale),
            }),
        unreadable > 0 &&
            __(':count rows that cannot be read', {
                count: formatCount(unreadable, locale),
            }),
    ].filter(Boolean);

    return parts.length > 0
        ? __('Skipped: :reasons', { reasons: parts.join(', ') })
        : __('Repeated ones are skipped');
}

export function StepReview({
    eyebrow,
    mode,
    fileAccounts,
    plan,
    accounts,
    built,
    unreadable,
    uncategorized,
    aiNote,
    confirmWipe,
    onConfirmWipeChange,
    locale,
    footer,
}: StepReviewProps) {
    const created = built.payload.categories.filter(
        (entry) => entry.action === 'create',
    ).length;
    const matched = built.payload.categories.length - created;
    const newAccounts = fileAccounts.filter(
        (account) => plan[account.key].action === 'create',
    ).length;
    const mapped = fileAccounts.filter(
        (account) => plan[account.key].action === 'map',
    ).length;
    const skippedAccounts = fileAccounts
        .filter((account) => plan[account.key].action === 'skip')
        .reduce((total, account) => total + account.count, 0);
    const balancesByKey = built.balances.reduce<Record<string, number>>(
        (tally, row) => ({
            ...tally,
            [row.account_key]: (tally[row.account_key] ?? 0) + 1,
        }),
        {},
    );
    const withBalances = fileAccounts
        .filter((_, index) => balancesByKey[accountPayloadKey(index)])
        .map((account) => displayName(account, plan, accounts));
    const manual = accounts.filter((account) => !account.connected);
    const connected = accounts.filter((account) => account.connected);

    return (
        <WizardScreen
            eyebrow={eyebrow}
            title={__('Ready to import')}
            description={__(
                'This is what we are going to create in your space. You can go back to any step to change it.',
            )}
            footer={footer}
        >
            <div className="flex flex-wrap gap-3">
                <StatCard
                    value={formatCount(newAccounts, locale)}
                    label={__('new accounts')}
                    note={
                        mapped > 0
                            ? __('and :count into yours', { count: mapped })
                            : undefined
                    }
                />
                <StatCard
                    value={formatCount(created, locale)}
                    label={__('new categories')}
                    note={
                        matched > 0
                            ? __('and :count merged with yours', {
                                  count: matched,
                              })
                            : undefined
                    }
                />
                <StatCard
                    value={formatCount(built.transactions.length, locale)}
                    label={__('transactions')}
                    note={skippedNote(skippedAccounts, unreadable, locale)}
                />
                <StatCard
                    value={formatCount(built.balances.length, locale)}
                    label={__('daily balances')}
                    note={
                        withBalances.length > 0
                            ? __('from :accounts', {
                                  accounts: joinNames(withBalances, locale),
                              })
                            : __('The file has none for these accounts')
                    }
                />
            </div>

            <SectionCard label={__('Accounts')}>
                {fileAccounts.map((account, index) => {
                    const entry = plan[account.key];
                    const balances =
                        balancesByKey[accountPayloadKey(index)] ?? 0;

                    return (
                        <SectionRow
                            key={account.key}
                            muted={entry.action === 'skip'}
                            title={
                                <>
                                    {displayName(account, plan, accounts)}
                                    {accountActionPill(entry, fileAccounts)}
                                </>
                            }
                            meta={[
                                __(':count transactions', {
                                    count: formatCount(account.count, locale),
                                }),
                                balances > 0 &&
                                    __(':count balances', {
                                        count: formatCount(balances, locale),
                                    }),
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        />
                    );
                })}
            </SectionCard>

            {aiNote && uncategorized > 0 && (
                <Notice icon={Sparkles}>{aiNote}</Notice>
            )}

            {mode === 'wipe' && (
                <Notice tone="danger">
                    <span>
                        {manual.length > 0
                            ? __(
                                  'Before importing we will delete :accounts, with their :count transactions and balances. This cannot be undone.',
                                  {
                                      accounts: joinNames(
                                          manual.map((account) => account.name),
                                          locale,
                                      ),
                                      count: formatCount(
                                          manual.reduce(
                                              (total, account) =>
                                                  total +
                                                  account.transactions_count,
                                              0,
                                          ),
                                          locale,
                                      ),
                                  },
                              )
                            : __(
                                  'You have no manual accounts, so nothing is deleted.',
                              )}{' '}
                        {connected.length > 0 &&
                            __('Your connected accounts stay as they are.')}
                    </span>
                    <label className="flex items-center gap-2.5 font-medium">
                        <Checkbox
                            checked={confirmWipe}
                            onCheckedChange={(checked) =>
                                onConfirmWipeChange(checked === true)
                            }
                        />
                        {__('I understand my manual accounts will be deleted')}
                    </label>
                </Notice>
            )}
        </WizardScreen>
    );
}

/** A file account by the name it will have once imported. */
function displayName(
    account: FileAccount,
    plan: Record<string, AccountPlanEntry>,
    accounts: ContextAccount[],
): string {
    const entry = plan[account.key];

    if (entry.action === 'create') {
        return entry.name.trim() || account.name;
    }

    if (entry.action === 'map') {
        return (
            accounts.find((one) => one.id === entry.targetAccountId)?.name ??
            account.name
        );
    }

    return account.name;
}
