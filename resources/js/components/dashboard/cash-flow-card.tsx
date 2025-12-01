import { StatCard } from '@/components/dashboard/stat-card';
import { ArrowLeftRightIcon } from 'lucide-react';

interface CashFlowCardProps {
    income: number;
    expense: number;
    previous?: {
        income: number;
        expense: number;
    };
    loading?: boolean;
}

export function CashFlowCard({
    income,
    expense,
    previous,
    loading,
}: CashFlowCardProps) {
    const formatter = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });

    const currentNet = income - expense;

    const calculateTrend = () => {
        if (!previous) return undefined;
        const previousNet = previous.income - previous.expense;
        if (previousNet === 0) return undefined;

        const diff = currentNet - previousNet;
        const percentage = (diff / Math.abs(previousNet)) * 100;

        return {
            value: percentage,
            label: 'from previous period',
        };
    };

    return (
        <StatCard
            title="Cash Flow"
            value={formatter.format(currentNet / 100)}
            icon={ArrowLeftRightIcon}
            trend={calculateTrend()}
            description={`Income: ${formatter.format(income / 100)} • Expense: ${formatter.format(expense / 100)}`}
            loading={loading}
        />
    );
}
