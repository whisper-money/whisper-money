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
        color: 'from-blue-500 to-cyan-500',
        hasTransactions: true,
    },
    {
        type: 'savings',
        name: 'Savings',
        icon: PiggyBank,
        description: 'Save money for goals',
        color: 'from-emerald-500 to-green-500',
        hasTransactions: true,
    },
    {
        type: 'credit_card',
        name: 'Credit Card',
        icon: CreditCard,
        description: 'Track credit card spending',
        color: 'from-violet-500 to-purple-500',
        hasTransactions: true,
    },
    {
        type: 'investment',
        name: 'Investment',
        icon: LineChart,
        description: 'Stocks, ETFs, and portfolios',
        color: 'from-amber-500 to-orange-500',
        hasTransactions: false,
    },
    {
        type: 'retirement',
        name: 'Retirement',
        icon: TrendingUp,
        description: '401k, IRA, pension funds',
        color: 'from-rose-500 to-pink-500',
        hasTransactions: false,
    },
    {
        type: 'loan',
        name: 'Loan',
        icon: Building2,
        description: 'Mortgages and loans',
        color: 'from-slate-500 to-gray-500',
        hasTransactions: false,
    },
];

export function StepAccountTypes({ onContinue }: StepAccountTypesProps) {
    return (
        <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
            <div className="mb-8 flex h-20 w-20 animate-in items-center justify-center rounded-full bg-gradient-to-br from-cyan-400 to-blue-500 shadow-lg duration-500 zoom-in">
                <Banknote className="h-10 w-10 text-white" />
            </div>

            <h1 className="mb-4 text-center text-3xl font-bold tracking-tight">
                Account Types
            </h1>

            <p className="mb-8 max-w-lg text-center text-muted-foreground">
                Whisper Money supports different account types. Some track
                transactions, others just track balance over time.
            </p>

            <div className="mb-8 grid w-full max-w-2xl gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {accountTypes.map((account) => (
                    <div
                        key={account.type}
                        className="group relative overflow-hidden rounded-xl border bg-card p-4 transition-all hover:shadow-md"
                    >
                        <div
                            className={`mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-br ${account.color}`}
                        >
                            <account.icon className="h-5 w-5 text-white" />
                        </div>
                        <h3 className="mb-1 font-semibold">{account.name}</h3>
                        <p className="mb-2 text-xs text-muted-foreground">
                            {account.description}
                        </p>
                        <span
                            className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium ${
                                account.hasTransactions
                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                                    : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'
                            }`}
                        >
                            {account.hasTransactions
                                ? 'Tracks transactions & balance'
                                : 'Balance only'}
                        </span>
                    </div>
                ))}
            </div>

            <div className="mt-8">
                <Button
                    size="lg"
                    onClick={onContinue}
                    className="group gap-2 px-8"
                >
                    Create Your First Account
                    <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
                </Button>
            </div>
        </div>
    );
}
