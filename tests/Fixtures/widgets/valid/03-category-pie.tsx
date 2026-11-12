import { useWidgetData } from '@whisper-money/widget-sdk';
import { Cell, Legend, Pie, PieChart, ResponsiveContainer } from 'recharts';

const COLORS = [
    'var(--color-chart-1)',
    'var(--color-chart-2)',
    'var(--color-chart-3)',
    'var(--color-chart-4)',
];

export default function CategoryPie() {
    const { categories } = useWidgetData();

    return (
        <div className="p-4">
            <h3 className="mb-2 text-sm font-medium">Gasto por categoría</h3>
            <ResponsiveContainer width="100%" height={180}>
                <PieChart>
                    <Pie
                        data={categories}
                        dataKey="total"
                        nameKey="name"
                        innerRadius={40}
                        outerRadius={70}
                        paddingAngle={2}
                    >
                        {categories.map((category, index) => (
                            <Cell
                                key={category.name}
                                fill={COLORS.at(index % COLORS.length)}
                            />
                        ))}
                    </Pie>
                    <Legend
                        verticalAlign="bottom"
                        iconType="circle"
                        wrapperStyle={{ fontSize: 12 }}
                    />
                </PieChart>
            </ResponsiveContainer>
        </div>
    );
}
