import {
    Amount,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
    useWidgetData,
    useWidgetEnv,
} from '@whisper-money/widget-sdk';

export default function TopCategories() {
    const { categories } = useWidgetData();
    const { currency } = useWidgetEnv();
    const sorted = [...categories]
        .sort((a, b) => b.total - a.total)
        .slice(0, 5);

    return (
        <div className="p-4">
            <h3 className="mb-2 text-sm font-medium">Top categorías</h3>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Categoría</TableHead>
                        <TableHead className="text-right">Total</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {sorted.map((category) => (
                        <TableRow key={category.name}>
                            <TableCell>{category.name}</TableCell>
                            <TableCell className="text-right">
                                <Amount
                                    amount={category.total}
                                    currency={currency}
                                />
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
