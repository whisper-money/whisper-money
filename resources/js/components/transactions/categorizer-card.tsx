import { AccountName } from '@/components/accounts/account-name';
import { BankLogo } from '@/components/bank-logo';
import { AmountDisplay } from '@/components/ui/amount-display';
import { type AnimationState } from '@/hooks/use-categorize-transactions';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import { type Category, getCategoryColorClasses } from '@/types/category';
import { type DecryptedTransaction } from '@/types/transaction';
import { formatDateLong } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { CheckCircle2 } from 'lucide-react';

interface CategorizerCardProps {
    transaction: DecryptedTransaction | undefined;
    animationState: AnimationState;
    lastSelectedCategory: Category | null;
}

export function CategorizerCard({
    transaction,
    animationState,
    lastSelectedCategory,
}: CategorizerCardProps) {
    const locale = useLocale();

    return (
        <div className="relative">
            {animationState === 'success' && lastSelectedCategory && (
                <div className="flex items-center justify-center py-8">
                    <div className="animate-categorizer-success-pop flex flex-col items-center gap-3">
                        <div
                            className={cn(
                                'flex h-16 w-16 items-center justify-center rounded-full',
                                getCategoryColorClasses(
                                    lastSelectedCategory.color,
                                ).bg,
                            )}
                        >
                            <CheckCircle2
                                className={cn(
                                    'h-8 w-8',
                                    getCategoryColorClasses(
                                        lastSelectedCategory.color,
                                    ).text,
                                )}
                            />
                        </div>
                        <span className="text-sm font-medium text-zinc-600 dark:text-zinc-400">
                            {lastSelectedCategory.name}
                        </span>
                    </div>
                </div>
            )}

            {animationState !== 'success' && transaction && (
                <div
                    className={cn(
                        'rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl shadow-zinc-200/50 transition-all duration-300 dark:border-zinc-800 dark:bg-zinc-900 dark:shadow-zinc-900/50',
                        animationState === 'exiting' &&
                            'translate-y-[-20px] scale-95 opacity-0',
                        animationState === 'entering' &&
                            'animate-categorizer-card-enter',
                        animationState === 'idle' &&
                            'translate-y-0 scale-100 opacity-100',
                    )}
                >
                    <div className="flex flex-col gap-4">
                        <div className="space-y-6">
                            <div className="flex items-start justify-between">
                                <div className="flex flex-1 flex-col gap-4">
                                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        {formatDateLong(
                                            transaction.transaction_date,
                                            locale,
                                        )}
                                    </p>

                                    <h2 className="text-2xl whitespace-pre-wrap text-zinc-900 dark:text-zinc-100">
                                        {transaction.decryptedDescription ||
                                            'Encrypted'}
                                    </h2>

                                    {transaction.account && (
                                        <div className="flex items-center gap-2">
                                            <BankLogo
                                                src={transaction.bank?.logo}
                                                name={transaction.bank?.name}
                                                className="size-5"
                                            />
                                            <AccountName
                                                account={transaction.account}
                                                className="text-sm text-zinc-600 dark:text-zinc-400"
                                            />
                                        </div>
                                    )}

                                    {(transaction.creditor_name ||
                                        transaction.debtor_name) && (
                                        <dl className="flex flex-col gap-1 text-sm">
                                            {transaction.creditor_name && (
                                                <div className="flex items-center gap-2">
                                                    <dt className="text-muted-foreground">
                                                        {__('Creditor')}
                                                    </dt>
                                                    <dd className="text-zinc-700 dark:text-zinc-300">
                                                        {
                                                            transaction.creditor_name
                                                        }
                                                    </dd>
                                                </div>
                                            )}
                                            {transaction.debtor_name && (
                                                <div className="flex items-center gap-2">
                                                    <dt className="text-muted-foreground">
                                                        {__('Debtor')}
                                                    </dt>
                                                    <dd className="text-zinc-700 dark:text-zinc-300">
                                                        {
                                                            transaction.debtor_name
                                                        }
                                                    </dd>
                                                </div>
                                            )}
                                        </dl>
                                    )}
                                </div>
                            </div>

                            {transaction.decryptedNotes && (
                                <p className="text-sm text-muted-foreground">
                                    {transaction.decryptedNotes}
                                </p>
                            )}
                        </div>

                        <div className="mt-2 font-mono text-xl text-muted-foreground">
                            <AmountDisplay
                                amountInCents={transaction.amount}
                                currencyCode={transaction.currency_code}
                                variant="positive-highlight"
                                highlightPositive={transaction.amount >= 0}
                            />
                        </div>
                    </div>
                </div>
            )}

            <style>{`
                @keyframes categorizer-card-enter {
                    from {
                        opacity: 0;
                        transform: translateY(20px) scale(0.95);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0) scale(1);
                    }
                }

                @keyframes categorizer-success-pop {
                    0% {
                        opacity: 0;
                        transform: scale(0.5);
                    }
                    50% {
                        transform: scale(1.1);
                    }
                    100% {
                        opacity: 1;
                        transform: scale(1);
                    }
                }

                .animate-categorizer-card-enter {
                    animation: categorizer-card-enter 0.3s ease-out forwards;
                }

                .animate-categorizer-success-pop {
                    animation: categorizer-success-pop 0.4s ease-out forwards;
                }
            `}</style>
        </div>
    );
}
