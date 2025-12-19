import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { BudgetHistoryData } from '@/types/budget';
import { formatCurrency } from '@/utils/currency';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

interface Props {
    data: BudgetHistoryData[];
    categoryName: string;
}

export function BudgetHistoryChart({ data, categoryName }: Props) {
    const chartData = data.map((item) => ({
        period: new Date(item.period_start).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
        }),
        budgeted: item.budgeted / 100,
        spent: item.spent / 100,
    }));

    return (
        <Card>
            <CardHeader>
                <CardTitle>Spending History</CardTitle>
                <CardDescription>
                    Budgeted vs. Actual spending for {categoryName} over the
                    last {data.length} periods
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ResponsiveContainer width="100%" height={300}>
                    <BarChart data={chartData}>
                        <CartesianGrid strokeDasharray="3 3" />
                        <XAxis
                            dataKey="period"
                            tick={{ fontSize: 12 }}
                            tickLine={false}
                        />
                        <YAxis
                            tick={{ fontSize: 12 }}
                            tickLine={false}
                            tickFormatter={(value) =>
                                formatCurrency(value * 100)
                            }
                        />
                        <Tooltip
                            formatter={(value: number) =>
                                formatCurrency(value * 100)
                            }
                            contentStyle={{
                                backgroundColor: 'hsl(var(--background))',
                                border: '1px solid hsl(var(--border))',
                                borderRadius: '8px',
                            }}
                        />
                        <Legend />
                        <Bar
                            dataKey="budgeted"
                            fill="hsl(var(--primary))"
                            name="Budgeted"
                            radius={[4, 4, 0, 0]}
                        />
                        <Bar
                            dataKey="spent"
                            fill="hsl(var(--muted-foreground))"
                            name="Spent"
                            radius={[4, 4, 0, 0]}
                        />
                    </BarChart>
                </ResponsiveContainer>
            </CardContent>
        </Card>
    );
}

