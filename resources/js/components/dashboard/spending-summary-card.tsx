import { StatCard } from '@/components/dashboard/stat-card';
import { CreditCardIcon } from 'lucide-react';

interface SpendingSummaryCardProps {
    current: number;
    previous?: number;
    loading?: boolean;
}

export function SpendingSummaryCard({
    current,
    previous,
    loading,
}: SpendingSummaryCardProps) {
    const formatter = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });

    const calculateTrend = () => {
        if (!previous) return undefined;
        const diff = current - previous;
        const percentage = (diff / previous) * 100;

        return {
            value: percentage,
            label: 'from previous period',
        };
    };

    return (
        <StatCard
            title="Monthly Spending"
            value={formatter.format(current / 100)}
            icon={CreditCardIcon}
            trend={calculateTrend()}
            description="Total spending this month"
            loading={loading}
        />
    );
}
