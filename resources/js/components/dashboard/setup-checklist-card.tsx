import { UpdateBalanceDialog } from '@/components/accounts/update-balance-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import { categorizeHref } from '@/lib/categorize-href';
import { cn } from '@/lib/utils';
import { index as budgetsIndex } from '@/routes/budgets';
import { dismiss } from '@/routes/dashboard/setup';
import { type Account } from '@/types/account';
import {
    type SetupChecklist,
    type SetupChecklistStep,
} from '@/types/dashboard';
import { formatMonthFromYearMonth } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Link, router, useHttp } from '@inertiajs/react';
import { CheckIcon, XIcon } from 'lucide-react';
import { useState } from 'react';

type StepStatus = 'done' | 'next' | 'todo';

type StepAction =
    | { label: string; href: string }
    | { label: string; onClick: () => void };

interface StepView {
    key: SetupChecklistStep['key'];
    status: StepStatus;
    title: string;
    detail: string | null;
    action: StepAction | null;
}

/** The desktop grid takes one column per step, so four fit on one row. */
const GRID_COLUMNS: Record<number, string> = {
    1: 'lg:grid-cols-1',
    2: 'lg:grid-cols-2',
    3: 'lg:grid-cols-3',
    4: 'lg:grid-cols-4',
};

/**
 * "Finish setting up": the first days after onboarding, the dashboard opens
 * with the few steps that make it worth reading, each done or still to do,
 * and the first one left as the one primary action.
 *
 * A grid of steps on a wide screen; on a phone, one step at a time with the
 * progress as segments, the step after it named underneath.
 *
 * Hiding it is for good and kept on the user, like the monthly summary notice
 * beside it: it goes away the moment it is clicked and the request goes out
 * on its own, since a full visit would reload every deferred prop on the page.
 */
export function SetupChecklistCard({
    checklist,
    canDismiss,
}: {
    checklist: SetupChecklist;
    canDismiss: boolean;
}) {
    const [dismissed, setDismissed] = useState(false);
    const [balanceAccount, setBalanceAccount] = useState<Account | null>(null);
    const { post } = useHttp();
    const steps = useStepViews(checklist.steps, setBalanceAccount);

    if (dismissed) {
        return null;
    }

    const doneCount = steps.filter((step) => step.status === 'done').length;
    const progress = __(':done of :total done', {
        done: doneCount,
        total: steps.length,
    });

    return (
        <Card size="sm" role="region" aria-label={__('Finish setting up')}>
            <CardHeader className="flex-row items-center justify-between gap-3">
                <CardTitle role="heading" aria-level={2} className="text-base">
                    {__('Finish setting up')}
                </CardTitle>
                <div className="flex items-center gap-1">
                    <span className="text-sm text-muted-foreground">
                        {progress}
                    </span>
                    {canDismiss && (
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-8"
                            aria-label={__('Hide')}
                            onClick={() => {
                                setDismissed(true);
                                post(dismiss.url());
                            }}
                        >
                            <XIcon className="size-4" />
                        </Button>
                    )}
                </div>
            </CardHeader>
            <CardContent>
                <StepGrid steps={steps} />
                <NextStepCompact steps={steps} />
            </CardContent>

            {balanceAccount && (
                <UpdateBalanceDialog
                    account={balanceAccount}
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            setBalanceAccount(null);
                        }
                    }}
                    onSuccess={() => {
                        setBalanceAccount(null);
                        // The balance moves the net worth and the account
                        // cards too, not only this card.
                        router.reload();
                    }}
                />
            )}
        </Card>
    );
}

function StepGrid({ steps }: { steps: StepView[] }) {
    return (
        <ol
            className={cn(
                'hidden gap-3 md:grid md:grid-cols-2',
                GRID_COLUMNS[steps.length],
            )}
        >
            {steps.map((step) => (
                <li
                    key={step.key}
                    className={cn(
                        'flex flex-col items-start gap-1.5 rounded-lg border bg-background p-3.5',
                        step.status === 'next' && 'border-primary',
                    )}
                >
                    <StepStatusLabel status={step.status} />
                    <span className="text-sm font-medium">{step.title}</span>
                    {step.detail && (
                        <span className="text-sm text-muted-foreground">
                            {step.detail}
                        </span>
                    )}
                    {step.action && step.status !== 'done' && (
                        <StepActionButton
                            action={step.action}
                            primary={step.status === 'next'}
                            className="mt-1"
                        />
                    )}
                </li>
            ))}
        </ol>
    );
}

/** The phone's version: the progress, the step to take now and the one after. */
function NextStepCompact({ steps }: { steps: StepView[] }) {
    const next = steps.find((step) => step.status === 'next');
    const then = steps.find((step) => step.status === 'todo');

    return (
        <div className="flex flex-col gap-2.5 md:hidden">
            <div className="flex gap-1" aria-hidden>
                {steps.map((step) => (
                    <div
                        key={step.key}
                        className={cn(
                            'h-1 flex-1 rounded-full',
                            step.status === 'done' ? 'bg-primary' : 'bg-muted',
                        )}
                    />
                ))}
            </div>
            {next && (
                <>
                    <span className="text-base font-semibold">
                        {next.title}
                    </span>
                    {then && (
                        <span className="text-sm text-muted-foreground">
                            {__('Then: :step', { step: then.title })}
                        </span>
                    )}
                    {next.action && (
                        <StepActionButton
                            action={next.action}
                            primary
                            className="mt-1 w-full"
                        />
                    )}
                </>
            )}
        </div>
    );
}

function StepStatusLabel({ status }: { status: StepStatus }) {
    if (status === 'done') {
        return (
            <span className="inline-flex items-center gap-1 text-[13px] font-medium text-success">
                <CheckIcon className="size-3.5" />
                {__('Done')}
            </span>
        );
    }

    return (
        <span className="text-[13px] font-medium text-muted-foreground">
            {status === 'next' ? __('Next') : __('To do')}
        </span>
    );
}

function StepActionButton({
    action,
    primary,
    className,
}: {
    action: StepAction;
    primary: boolean;
    className?: string;
}) {
    const variant = primary ? 'default' : 'outline';

    if ('href' in action) {
        return (
            <Button asChild size="sm" variant={variant} className={className}>
                <Link href={action.href}>{action.label}</Link>
            </Button>
        );
    }

    return (
        <Button
            size="sm"
            variant={variant}
            className={className}
            onClick={action.onClick}
        >
            {action.label}
        </Button>
    );
}

/**
 * Each step's words and action, with the first one not done marked as the
 * next: the one primary action on the card.
 */
function useStepViews(
    steps: SetupChecklistStep[],
    openBalance: (account: Account) => void,
): StepView[] {
    const { formatAmount } = useFormatAmount();
    const locale = useLocale();
    const nextIndex = steps.findIndex((step) => !step.done);

    return steps.map((step, index) => {
        const status: StepStatus = step.done
            ? 'done'
            : index === nextIndex
              ? 'next'
              : 'todo';

        switch (step.key) {
            case 'imported': {
                const from = formatMonthFromYearMonth(
                    step.first_date.slice(0, 7),
                    locale,
                );
                const to = formatMonthFromYearMonth(
                    step.last_date.slice(0, 7),
                    locale,
                );
                const months = from === to ? from : `${from} – ${to}`;

                return {
                    key: step.key,
                    status,
                    title: __(':count transactions imported', {
                        count: step.count,
                    }),
                    detail: step.account_name
                        ? __(':months, :account', {
                              months,
                              account: step.account_name,
                          })
                        : __(':months, :count accounts', {
                              months,
                              count: step.accounts_count,
                          }),
                    action: null,
                };
            }
            case 'spending_limit':
                return {
                    key: step.key,
                    status,
                    title: step.done
                        ? __('Monthly spending limit set')
                        : __('Set a monthly spending limit'),
                    detail: spendingLimitDetail(step, formatAmount),
                    action: step.done
                        ? null
                        : { label: __('Set limit'), href: budgetsIndex().url },
                };
            case 'categorize':
                return {
                    key: step.key,
                    status,
                    title: step.done
                        ? __('Transactions categorized')
                        : __('Categorize :count transactions', {
                              count: step.count,
                          }),
                    detail: null,
                    action: step.done
                        ? null
                        : { label: __('Categorize'), href: categorizeHref() },
                };
            case 'balance':
                return {
                    key: step.key,
                    status,
                    title:
                        step.account.type === 'loan'
                            ? __('Add what you still owe on :account', {
                                  account: step.account.name,
                              })
                            : __('Add a balance to :account', {
                                  account: step.account.name,
                              }),
                    detail:
                        step.others_count > 0
                            ? __('And :count more accounts without one', {
                                  count: step.others_count,
                              })
                            : null,
                    action: {
                        label: __('Add balance'),
                        onClick: () => openBalance(step.account),
                    },
                };
        }
    });
}

function spendingLimitDetail(
    step: Extract<SetupChecklistStep, { key: 'spending_limit' }>,
    formatAmount: ReturnType<typeof useFormatAmount>['formatAmount'],
): string | null {
    if (step.limit === null) {
        return null;
    }

    const limit = formatAmount(step.limit, step.currency_code, {
        fractionDigits: 0,
    });

    if (step.under_last_month === null) {
        return __(':limit a month', { limit });
    }

    return __(':limit — :under under last month', {
        limit,
        under: formatAmount(step.under_last_month, step.currency_code, {
            fractionDigits: 0,
        }),
    });
}
