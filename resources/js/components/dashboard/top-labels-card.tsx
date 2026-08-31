import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import {
    CategoryBreakdownRow,
    trendFrom,
    type CategoryBreakdownAdapter,
} from '@/components/shared/category-breakdown-list';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useChartColors } from '@/hooks/use-chart-color-scheme';
import { useLast30Days } from '@/hooks/use-last-30-days';
import { cn } from '@/lib/utils';
import { SharedData } from '@/types';
import { type CategoryColor } from '@/types/category';
import { getLabelColorClasses } from '@/types/label';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { Tag } from 'lucide-react';

export interface LabelSpending {
    id: string;
    name: string;
    color: string;
    amount: number;
    previous_amount: number;
    total_amount: number;
}

/**
 * Spending per label over the last 30 days. Nothing labelled, no card: an empty
 * one would take up a dashboard slot to say nothing, so it stays hidden until
 * the user actually tags what they spend.
 */
export function TopLabelsCard({ labels }: { labels: LabelSpending[] }) {
    const { auth } = usePage<SharedData>().props;
    const { categoryBarColor } = useChartColors();

    const { dateFrom, dateTo } = useLast30Days();

    if (labels.length === 0 || !auth?.user) {
        return null;
    }

    const adapter: CategoryBreakdownAdapter<LabelSpending> = {
        getId: (item) => item.id,
        getKey: (item) => item.id,
        getName: (item) => item.name,
        getAmount: (item) => item.amount,
        getPercentage: (item) =>
            item.total_amount > 0 ? (item.amount / item.total_amount) * 100 : 0,
        getBarColor: (item, index) =>
            categoryBarColor(item.color as CategoryColor, index),
        renderLeading: (item) => {
            const color = getLabelColorClasses(item.color);

            return (
                <div
                    className={cn([
                        'flex size-6 shrink-0 items-center justify-center rounded-full',
                        `${color.bg} ${color.text}`,
                    ])}
                >
                    <Tag className="size-4" />
                </div>
            );
        },
        getHref: (item) =>
            transactionsIndex({
                query: {
                    label_ids: item.id,
                    date_from: dateFrom,
                    date_to: dateTo,
                },
            }).url,
        getTrend: (item) => trendFrom(item.amount, item.previous_amount),
    };

    return (
        <Card className="w-full">
            <CardHeader className="gap-2">
                <CardTitle>{__('Top labels')}</CardTitle>
                <CardDescription>{__('on the last 30 days')}</CardDescription>
            </CardHeader>
            <CardContent>
                <div className="space-y-3">
                    {labels.map((item, index) => (
                        <CategoryBreakdownRow
                            key={item.id}
                            item={item}
                            index={index}
                            currencyCode={auth.user.currency_code}
                            adapter={adapter}
                        />
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}
