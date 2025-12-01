import { StatCard } from '@/components/dashboard/stat-card';
import { WalletIcon } from 'lucide-react';

interface NetWorthCardProps {
    current: number;
    previous: number;
    loading?: boolean;
}

export function NetWorthCard({
    current,
    previous,
    loading,
}: NetWorthCardProps) {
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
            title="Net Worth"
            value={formatter.format(current / 100)}
            icon={WalletIcon}
            trend={calculateTrend()}
            loading={loading}
        />
    );
}
