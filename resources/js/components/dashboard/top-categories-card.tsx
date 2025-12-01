import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Category } from '@/types/category';
import * as Icons from 'lucide-react';
import { LucideIcon } from 'lucide-react';

interface TopCategoriesCardProps {
    categories: Array<{ category: Category; amount: number }>;
    loading?: boolean;
}

export function TopCategoriesCard({
    categories,
    loading,
}: TopCategoriesCardProps) {
    const formatter = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });

    if (loading) {
        return (
            <Card className="col-span-3">
                <CardHeader>
                    <CardTitle>Top Spending Categories</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="h-[200px] w-full animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                </CardContent>
            </Card>
        );
    }

    return (
        <Card className="col-span-3">
            <CardHeader>
                <CardTitle>Top Spending Categories</CardTitle>
            </CardHeader>
            <CardContent>
                <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                    {categories.map((item) => {
                        const Icon = (Icons[
                            item.category.icon as keyof typeof Icons
                        ] || Icons.HelpCircle) as LucideIcon;
                        return (
                            <div
                                key={item.category.id}
                                className="flex items-center space-x-4 rounded-md border p-4"
                            >
                                <div
                                    className="flex size-10 items-center justify-center rounded-full"
                                    style={{
                                        backgroundColor: `${item.category.color}20`,
                                        color: item.category.color,
                                    }}
                                >
                                    <Icon className="size-5" />
                                </div>
                                <div className="flex-1 space-y-1">
                                    <p className="text-sm leading-none font-medium">
                                        {item.category.name}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {formatter.format(item.amount / 100)}
                                    </p>
                                </div>
                            </div>
                        );
                    })}
                    {categories.length === 0 && (
                        <div className="col-span-full py-8 text-center text-muted-foreground">
                            No spending data this month
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
