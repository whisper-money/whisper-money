import {
    formatMoney,
    useWidgetData,
    useWidgetEnv,
} from '@whisper-money/widget-sdk';
import {
    Area,
    AreaChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
} from 'recharts';

export default function Cashflow() {
    const { cashflow } = useWidgetData();
    const { currency, privacyMode } = useWidgetEnv();

    return (
        <div className="flex flex-col gap-2 p-4">
            <h3 className="text-sm font-medium">Flujo de caja</h3>
            <ResponsiveContainer width="100%" height={160}>
                <AreaChart data={cashflow}>
                    <defs>
                        <linearGradient
                            id="cashflowFill"
                            x1="0"
                            y1="0"
                            x2="0"
                            y2="1"
                        >
                            <stop
                                offset="5%"
                                stopColor="var(--color-chart-1)"
                                stopOpacity={0.4}
                            />
                            <stop
                                offset="95%"
                                stopColor="var(--color-chart-1)"
                                stopOpacity={0}
                            />
                        </linearGradient>
                    </defs>
                    <CartesianGrid vertical={false} strokeDasharray="3 3" />
                    <XAxis
                        dataKey="month"
                        tickFormatter={(month) => month.slice(5)}
                    />
                    <Tooltip
                        labelFormatter={(label) => `Mes ${label}`}
                        formatter={(value) =>
                            privacyMode ? '•••' : formatMoney(value, currency)
                        }
                        contentStyle={{ borderRadius: 8, fontSize: 12 }}
                    />
                    <Area
                        type="monotone"
                        dataKey="net"
                        stroke="var(--color-chart-1)"
                        fill="url(#cashflowFill)"
                    />
                </AreaChart>
            </ResponsiveContainer>
        </div>
    );
}
