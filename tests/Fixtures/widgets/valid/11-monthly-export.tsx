import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    ChartTooltip,
    formatMoney,
    get,
    useWidgetData,
    useWidgetEnv,
    wm,
} from '@whisper-money/widget-sdk';
import { Download } from 'lucide-react';
import { Line, LineChart, ResponsiveContainer, XAxis } from 'recharts';

export default function MonthlyExport() {
    const { cashflow } = useWidgetData();
    const { currency } = useWidgetEnv();
    const field = 'net';
    const rows = cashflow.map((row) => ({
        month: row.month,
        net: get(row, field),
    }));
    const exportCsv = () => wm.download('flujo-mensual', rows);

    return (
        <Card className="h-full gap-2 py-4">
            <CardHeader className="flex items-center justify-between px-4">
                <CardTitle className="text-sm">Exportar flujo</CardTitle>
                <Button variant="outline" size="sm" onClick={exportCsv}>
                    <Download size={14} />
                    CSV
                </Button>
            </CardHeader>
            <CardContent className="px-4">
                <ResponsiveContainer width="100%" height={120}>
                    <LineChart data={rows}>
                        <XAxis dataKey="month" tickLine={false} />
                        <ChartTooltip
                            valueFormatter={(value) =>
                                formatMoney(value, currency)
                            }
                        />
                        <Line
                            dataKey="net"
                            stroke="var(--color-chart-2)"
                            isAnimationActive={false}
                        />
                    </LineChart>
                </ResponsiveContainer>
            </CardContent>
        </Card>
    );
}
