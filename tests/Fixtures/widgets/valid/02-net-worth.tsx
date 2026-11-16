import { Amount, useWidgetData, useWidgetEnv } from '@whisper-money/widget-sdk';
import { TrendingUp } from 'lucide-react';
import { Line, LineChart, ResponsiveContainer, YAxis } from 'recharts';

export default function NetWorth() {
    const { netWorth } = useWidgetData();
    const { currency } = useWidgetEnv();
    const latest = netWorth.at(-1);

    return (
        <div className="flex flex-col gap-1 p-4">
            <span className="flex items-center gap-1 text-xs text-muted-foreground">
                <TrendingUp className="size-3" aria-hidden="true" />
                Patrimonio neto
            </span>
            <Amount
                amount={latest.value}
                currency={currency}
                className="text-2xl font-semibold tabular-nums"
            />
            <ResponsiveContainer width="100%" height={80}>
                <LineChart data={netWorth}>
                    <YAxis hide domain={['dataMin', 'dataMax']} />
                    <Line
                        type="monotone"
                        dataKey="value"
                        stroke="var(--color-chart-2)"
                        strokeWidth={2}
                        dot={false}
                    />
                </LineChart>
            </ResponsiveContainer>
        </div>
    );
}
