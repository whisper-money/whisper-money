import {
    cn,
    formatMoney,
    useWidgetData,
    useWidgetEnv,
} from '@whisper-money/widget-sdk';
import { ShoppingCart } from 'lucide-react';
import { useMemo } from 'react';
import { Bar, BarChart, ResponsiveContainer, Tooltip, XAxis } from 'recharts';

export default function Widget() {
    const { groceries } = useWidgetData();
    const { isCompact, privacyMode, currency } = useWidgetEnv();

    const months = useMemo(() => {
        const totals = new Map();

        for (const transaction of groceries.data) {
            const month = transaction.date.slice(0, 7);
            totals.set(
                month,
                (totals.get(month) ?? 0) + Math.abs(transaction.amount),
            );
        }

        return Array.from(totals.entries()).map(([month, total]) => ({
            month,
            total,
        }));
    }, [groceries]);

    return (
        <div
            className={cn('flex h-full flex-col gap-2 p-4', isCompact && 'p-2')}
        >
            <h3 className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                <ShoppingCart size={14} />
                Supermercado por mes
            </h3>
            <ResponsiveContainer width="100%" height={160}>
                <BarChart data={months}>
                    <XAxis
                        dataKey="month"
                        tickLine={false}
                        axisLine={false}
                        tick={{ fontSize: 12 }}
                    />
                    <Tooltip
                        formatter={(value) =>
                            privacyMode ? '•••' : formatMoney(value, currency)
                        }
                    />
                    <Bar
                        dataKey="total"
                        fill="var(--color-chart-1)"
                        radius={[4, 4, 0, 0]}
                    />
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
}
