import { Button } from '@/components/ui/button';
import {
    ArrowRight,
    Banknote,
    Building2,
    CreditCard,
    LineChart,
    PiggyBank,
    TrendingUp,
    Wallet,
} from 'lucide-react';

interface StepAccountTypesProps {
    onContinue: () => void;
}

const accountTypes = [
    {
        type: 'checking',
        name: 'Checking',
        icon: Wallet,
        description: 'Daily spending and transactions',
        hasTransactions: true,
    },
    {
        type: 'savings',
        name: 'Savings',
        icon: PiggyBank,
        description: 'Save money for goals',
        hasTransactions: true,
    },
    {
        type: 'credit_card',
        name: 'Credit Card',
        icon: CreditCard,
        description: 'Track credit card spending',
        hasTransactions: true,
    },
    {
        type: 'investment',
        name: 'Investment',
        icon: LineChart,
        description: 'Stocks, ETFs, and portfolios',
        hasTransactions: false,
    },
    {
        type: 'retirement',
        name: 'Retirement',
        icon: TrendingUp,
        description: '401k, IRA, pension funds',
        hasTransactions: false,
    },
    {
        type: 'loan',
        name: 'Loan',
        icon: Building2,
        description: 'Mortgages and loans',
        hasTransactions: false,
    },
];

export function StepAccountTypes({ onContinue }: StepAccountTypesProps) {
    return (
        <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
            <div className="mb-4 sm:mb-8 flex h-20 w-20 animate-in items-center justify-center rounded-full bg-gradient-to-br from-cyan-400 to-blue-500 shadow-lg duration-500 zoom-in">
                <Banknote className="h-10 w-10 text-white" />
            </div>

            <h1 className="mb-2 sm:mb-4 text-center text-3xl font-bold tracking-tight">
                Account Types
            </h1>

            <p className="mb-8 max-w-lg text-center text-muted-foreground">
                There different account types. Some track
                transactions, others just track balance over time.
            </p>

            <div className="grid w-full max-w-2xl gap-3 sm:grid-cols-2">
                {accountTypes.map((account) => (
                    <div
                        key={account.type}
                        className="flex flex-row items-center sm:items-start gap-2 group relative overflow-hidden rounded-xl border bg-card p-3 sm:p-4 transition-all hover:shadow-md"
                    >
                        <div className='flex flex-col items-start gap-1 sm:gap-2 w-full'>
                            <div className='flex flex-row items-center justify-between sm:items-start w-full gap-2'>
                                <div className="flex items-center gap-2">
                                    <account.icon className={`size-4 stroke-muted-foreground`} />
                                    <h3 className="font-semibold">{account.name}</h3>
                                </div>

                                <span
                                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium ${account.hasTransactions
                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                                        : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'
                                        }`}
                                >
                                    {account.hasTransactions
                                        ? 'Transactions + Balance'
                                        : 'Balance'}
                                </span>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {account.description}
                            </p>
                        </div>
                    </div>
                ))}
            </div>

            <div className="mt-8 w-full sm:w-auto">
                <Button
                    size="lg"
                    onClick={onContinue}
                    className="group gap-2 !px-8 w-full sm:w-auto"
                >
                    Create Your First Account
                    <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
                </Button>
            </div>
        </div>
    );
}
