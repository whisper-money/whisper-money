import {
    Notice,
    SectionCard,
    SectionRow,
    StatCard,
    WizardScreen,
} from '@/components/full-import/full-import-layout';
import { accountActionPill } from '@/components/full-import/step-accounts';
import { joinNames } from '@/components/full-import/step-existing';
import { UnreadableRows } from '@/components/full-import/unreadable-rows';
import { Checkbox } from '@/components/ui/checkbox';
import { type DuplicateEstimate } from '@/hooks/use-duplicate-estimate';
import {
    countLabel,
    formatCount,
    transactionCount,
} from '@/lib/full-import-format';
import {
    accountPayloadKey,
    IGNORED_KEY,
    OWN_TRANSFER_KEY,
    type BuiltImport,
} from '@/lib/full-import-plan';
import {
    type AccountPlanEntry,
    type ContextAccount,
    type FileAccount,
    type FullImportMode,
    type UnreadableRow,
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
    unreadable: UnreadableRow[];
    uncategorized: number;
    aiNote: string | null;
    confirmWipe: boolean;
    onConfirmWipeChange: (confirmed: boolean) => void;
    /** How much of what goes into the user's own accounts is there already. */
    estimate: DuplicateEstimate | null;
    checkingDuplicates: boolean;
    locale: string;
    footer: ReactNode;
}

/** "36 new, about 4 already there": an estimate, so worded as one. */
function likelyNewPhrase(
    total: number,
    existing: number,
    locale: string,
): string {
    const fresh = total - existing;
    const freshPart = countLabel(
        fresh,
        __('1 new'),
        __(':count new', { count: formatCount(fresh, locale) }),
    );

    if (existing === 0) {
        return `${freshPart}, ${__('none seem to be there yet')}`;
    }

    return `${freshPart}, ${countLabel(
        existing,
        __('about 1 already there'),
        __('about :count already there', {
            count: formatCount(existing, locale),
        }),
    )}`;
}

/** What the transactions card says beyond the count. */
function transactionsNote(
    built: BuiltImport,
    estimate: DuplicateEstimate | null,
    checking: boolean,
    skipped: string | undefined,
    locale: string,
): string | undefined {
    const duplicates = estimate
        ? likelyNewPhrase(built.transactions.length, estimate.existing, locale)
        : checking
          ? __('Checking what is already there…')
          : undefined;

    return [duplicates, skipped].filter(Boolean).join(' · ') || undefined;
}

/** What the review says about the transactions nobody will import. */
function skippedNote(
    skippedAccounts: number,
    unreadable: number,
    mode: FullImportMode,
    hasEstimate: boolean,
    locale: string,
): string | undefined {
    const parts = [
        skippedAccounts > 0 &&
            countLabel(
                skippedAccounts,
                __('1 from an account you are not importing'),
                __(':count from accounts you are not importing', {
                    count: formatCount(skippedAccounts, locale),
                }),
            ),
        unreadable > 0 &&
            countLabel(
                unreadable,
                __('1 row that cannot be read'),
                __(':count rows that cannot be read', {
                    count: formatCount(unreadable, locale),
                }),
            ),
    ].filter(Boolean);

    if (parts.length > 0) {
        return __('Skipped: :reasons', { reasons: parts.join(', ') });
    }

    // Starting from scratch leaves no account of the user's to find a
    // duplicate in, and an estimate already says how many there are.
    return mode === 'wipe' || hasEstimate
        ? undefined
        : __('Ones already in your accounts are skipped');
}

/** How many balances each plan account key carries. */
function balancesPerAccount(built: BuiltImport): Map<string, number> {
    const tally = new Map<string, number>();

    for (const row of built.balances) {
        tally.set(row.account_key, (tally.get(row.account_key) ?? 0) + 1);
    }

    return tally;
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
    estimate,
    checkingDuplicates,
    locale,
    footer,
}: StepReviewProps) {
    const created = built.payload.categories.filter(
        (entry) => entry.action === 'create',
    ).length;
    // The transfer targets are counted on the categories step as transfers,
    // not as categories merged with the user's, so they stay out here too.
    const matched = built.payload.categories.filter(
        (entry) =>
            entry.action === 'match' &&
            entry.key !== OWN_TRANSFER_KEY &&
            entry.key !== IGNORED_KEY,
    ).length;
    const newAccounts = fileAccounts.filter(
        (account) => plan[account.key].action === 'create',
    ).length;
    const mapped = fileAccounts.filter(
        (account) => plan[account.key].action === 'map',
    ).length;
    const skippedAccounts = fileAccounts
        .filter((account) => plan[account.key].action === 'skip')
        .reduce((total, account) => total + account.count, 0);
    const balancesByKey = balancesPerAccount(built);
    const withBalances = fileAccounts
        .filter((_, index) => balancesByKey.has(accountPayloadKey(index)))
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
                    label={countLabel(
                        newAccounts,
                        __('new account'),
                        __('new accounts'),
                    )}
                    note={
                        mapped > 0
                            ? countLabel(
                                  mapped,
                                  __('and 1 into yours'),
                                  __('and :count into yours', {
                                      count: mapped,
                                  }),
                              )
                            : undefined
                    }
                />
                <StatCard
                    value={formatCount(created, locale)}
                    label={countLabel(
                        created,
                        __('new category'),
                        __('new categories'),
                    )}
                    note={
                        matched > 0
                            ? countLabel(
                                  matched,
                                  __('and 1 merged with yours'),
                                  __('and :count merged with yours', {
                                      count: matched,
                                  }),
                              )
                            : undefined
                    }
                />
                <StatCard
                    value={formatCount(built.transactions.length, locale)}
                    label={countLabel(
                        built.transactions.length,
                        __('transaction'),
                        __('transactions'),
                    )}
                    note={transactionsNote(
                        built,
                        estimate,
                        checkingDuplicates,
                        skippedNote(
                            skippedAccounts,
                            unreadable.length,
                            mode,
                            estimate !== null || checkingDuplicates,
                            locale,
                        ),
                        locale,
                    )}
                />
                <StatCard
                    value={formatCount(built.balances.length, locale)}
                    label={countLabel(
                        built.balances.length,
                        __('daily balance'),
                        __('daily balances'),
                    )}
                    note={
                        withBalances.length > 0
                            ? __('from :accounts', {
                                  accounts: joinNames(withBalances),
                              })
                            : __('The file has none for these accounts')
                    }
                />
            </div>

            <UnreadableRows rows={unreadable} locale={locale} />

            <SectionCard label={__('Accounts')}>
                {fileAccounts.map((account, index) => {
                    const entry = plan[account.key];
                    const balances =
                        balancesByKey.get(accountPayloadKey(index)) ?? 0;

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
                                transactionCount(account.count, locale),
                                estimate?.byAccount[accountPayloadKey(index)] &&
                                    likelyNewPhrase(
                                        account.count,
                                        estimate.byAccount[
                                            accountPayloadKey(index)
                                        ],
                                        locale,
                                    ),
                                balances > 0 &&
                                    countLabel(
                                        balances,
                                        __('1 balance'),
                                        __(':count balances', {
                                            count: formatCount(
                                                balances,
                                                locale,
                                            ),
                                        }),
                                    ),
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
                                  'Before importing we will delete :accounts, with their transactions and balances (:transactions). This cannot be undone.',
                                  {
                                      accounts: joinNames(
                                          manual.map((account) => account.name),
                                      ),
                                      transactions: transactionCount(
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
