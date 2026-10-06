import { index as importIndex } from '@/actions/App/Http/Controllers/Settings/FullImportController';
import {
    Notice,
    SectionCard,
    SectionRow,
} from '@/components/full-import/full-import-layout';
import { StepFilled } from '@/components/onboarding/step-screen';
import { Button } from '@/components/ui/button';
import {
    accountCount,
    aiOutcomeNote,
    categoryCount,
    countLabel,
    dailyBalanceCount,
    formatCount,
    sourceLabel,
    transactionCount,
} from '@/lib/full-import-format';
import { list as accountsList } from '@/routes/accounts';
import { index as transactionsIndex } from '@/routes/transactions';
import { type ImportStatus } from '@/types/full-import';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { Check, Sparkles, TriangleAlert } from 'lucide-react';

/** "1 skipped", "12 skipped". */
function skippedCount(count: number, locale: string): string {
    return countLabel(
        count,
        __('1 skipped'),
        __(':count skipped', { count: formatCount(count, locale) }),
    );
}

/**
 * What the import added up to: what it created, and how many of the user's
 * own accounts it wrote into as well.
 */
function outcomeSentence(status: ImportStatus, locale: string): string {
    const stats = status.stats;
    const mapped = stats.accounts?.mapped ?? 0;
    const wroteNothing =
        (stats.accounts?.created ?? 0) +
            (stats.categories?.created ?? 0) +
            (stats.transactions?.imported ?? 0) +
            (stats.balances?.imported ?? 0) ===
        0;

    // A second import of the same file: saying "we created 0 accounts and
    // added 0 transactions" reads like a failure when it is the point.
    if (wroteNothing) {
        return __('Nothing new: everything in the file was already here.');
    }

    const created = __(
        'We created :accounts and :categories, and added :transactions and :balances.',
        {
            accounts: accountCount(stats.accounts?.created ?? 0, locale),
            categories: categoryCount(stats.categories?.created ?? 0, locale),
            transactions: transactionCount(
                stats.transactions?.imported ?? 0,
                locale,
            ),
            balances: dailyBalanceCount(stats.balances?.imported ?? 0, locale),
        },
    );

    if (mapped === 0) {
        return created;
    }

    return `${created} ${countLabel(
        mapped,
        __('1 of your accounts got transactions too.'),
        __(':count of your accounts got transactions too.', {
            count: mapped,
        }),
    )}`;
}

interface StepDoneProps {
    status: ImportStatus;
    variant: 'page' | 'embedded';
    /** Rows the browser never sent: unreadable, or from skipped accounts. */
    clientSkipped: { label: string; count: number }[];
    onFinished: () => void;
    locale: string;
}

export function StepDone({
    status,
    variant,
    clientSkipped,
    onFinished,
    locale,
}: StepDoneProps) {
    const stats = status.stats;

    if (status.status === 'failed') {
        return (
            <div className="mx-auto flex w-full max-w-xl flex-col gap-6">
                <span className="flex size-12 items-center justify-center rounded-full bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-200">
                    <TriangleAlert className="size-6" />
                </span>
                <h1 className="text-3xl font-semibold tracking-tight">
                    {__('The import stopped before it finished')}
                </h1>
                <p className="text-[15px] text-muted-foreground">
                    {status.mode === 'wipe'
                        ? __(
                              'Undo it from Settings to remove what it imported. What was deleted before the import started cannot be brought back.',
                          )
                        : __(
                              'Part of it may already be in. Undo it from Settings and try again.',
                          )}
                </p>
                <div className="flex flex-wrap gap-3">
                    {variant === 'page' ? (
                        <Button asChild>
                            <Link href={importIndex()}>
                                {__('Go to Settings')}
                            </Link>
                        </Button>
                    ) : (
                        <Button onClick={onFinished}>
                            {__('Back to accounts')}
                        </Button>
                    )}
                </div>
            </div>
        );
    }

    const aiNote = aiOutcomeNote(stats, locale);
    const duplicates = (stats.per_account ?? []).filter(
        (account) => account.duplicates > 0,
    );

    return (
        <div className="mx-auto flex w-full max-w-xl flex-col gap-7">
            <span className="flex size-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200">
                <Check className="size-6" />
            </span>
            <div className="flex flex-col gap-2.5">
                <h1 className="text-3xl leading-[1.14] font-semibold tracking-tight">
                    {status.source === 'banktrack'
                        ? __('Your :source data is here', {
                              source: sourceLabel(status.source),
                          })
                        : __('Your data is here')}
                </h1>
                <p className="text-[15px] text-muted-foreground">
                    {outcomeSentence(status, locale)}
                </p>
            </div>

            {aiNote && <Notice icon={Sparkles}>{aiNote}</Notice>}

            <SectionCard>
                <SectionRow
                    title={__('Transactions imported')}
                    meta={formatCount(
                        stats.transactions?.imported ?? 0,
                        locale,
                    )}
                />
                {duplicates.map((account) => (
                    <SectionRow
                        key={account.account_id}
                        title={__('Repeated in :name', { name: account.name })}
                        meta={skippedCount(account.duplicates, locale)}
                    />
                ))}
                {clientSkipped
                    .filter((line) => line.count > 0)
                    .map((line) => (
                        <SectionRow
                            key={line.label}
                            title={line.label}
                            meta={skippedCount(line.count, locale)}
                        />
                    ))}
            </SectionCard>

            <div className="flex flex-wrap gap-3">
                {variant === 'page' ? (
                    <>
                        <Button asChild>
                            <Link href={transactionsIndex()}>
                                {__('View transactions')}
                            </Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href={accountsList()}>
                                {__('View accounts')}
                            </Link>
                        </Button>
                    </>
                ) : (
                    <Button onClick={onFinished}>
                        {__('Back to accounts')}
                    </Button>
                )}
            </div>

            {variant === 'page' && (
                <p className="text-[13px] text-muted-foreground">
                    <StepFilled
                        sentence={__(
                            'Something off? You can :link from Settings.',
                        )}
                        values={{
                            link: (
                                <Link
                                    href={importIndex()}
                                    className="font-medium text-foreground underline underline-offset-4"
                                >
                                    {__('undo the import')}
                                </Link>
                            ),
                        }}
                    />
                </p>
            )}
        </div>
    );
}
