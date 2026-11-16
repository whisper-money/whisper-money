import { get, useWidgetData } from '@whisper-money/widget-sdk';
import { useMemo } from 'react';
import {
    Bar,
    BarChart,
    ReferenceLine,
    ResponsiveContainer,
    XAxis,
    YAxis,
} from 'recharts';

export default function Labels() {
    const { labels } = useWidgetData();
    const average = useMemo(
        () =>
            labels.reduce((sum, label) => sum + label.total, 0) / labels.length,
        [labels],
    );
    const firstKey = Object.keys(labels[0])[0];

    return (
        <div className="flex flex-col gap-2 p-4">
            <h3 className="text-sm font-medium">
                Gasto por etiqueta ({String(get(labels[0], firstKey))})
            </h3>
            <ResponsiveContainer width="100%" height={160}>
                <BarChart data={labels} layout="vertical">
                    <XAxis type="number" hide />
                    <YAxis
                        type="category"
                        dataKey="name"
                        width={80}
                        tickLine={false}
                    />
                    <ReferenceLine
                        x={average}
                        stroke="var(--color-muted-foreground)"
                        strokeDasharray="4 4"
                        label="Media"
                    />
                    <Bar
                        dataKey="total"
                        fill="var(--color-chart-3)"
                        radius={4}
                    />
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
}
