import {
    index,
    show,
} from '@/actions/App/Http/Controllers/MonthlySummaryController';
import { PageHeader } from '@/components/page-header';
import { Card } from '@/components/ui/card';
import { EmptyState, EmptyStateDescription } from '@/components/ui/empty-state';
import { useLocale } from '@/hooks/use-locale';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, Link } from '@inertiajs/react';
import { ChevronRightIcon } from 'lucide-react';

/**
 * Every monthly report that has been sent, newest first.
 *
 * It exists because a report about a closed month should not stop existing when
 * the email is archived, and because the shareable card lives here too — the
 * inbox is one way in, not the only one.
 */
type SummaryRow = {
    id: string;
    period: string;
    card: string;
    complete: boolean;
    sent_at: string | null;
    shared: boolean;
    payload: {
        cashflow?: { savings_rate?: number };
        streak_months?: number;
    };
};

interface Props {
    summaries: SummaryRow[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Summaries', href: index().url },
];

function monthLabel(period: string, locale: string): string {
    const [year, month] = period.split('-').map(Number);

    return new Date(year, month - 1, 1).toLocaleDateString(locale, {
        month: 'long',
        year: 'numeric',
    });
}

export default function MonthlySummariesIndex({ summaries }: Props) {
    const locale = useLocale();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Monthly summaries')} />

            <div className="mx-auto flex w-full max-w-page flex-col gap-6 p-4">
                <PageHeader
                    title={__('Monthly summaries')}
                    description={__(
                        'Every month we close, with the figures frozen as they were and the card you can share.',
                    )}
                />

                {summaries.length === 0 ? (
                    <EmptyState>
                        <EmptyStateDescription>
                            {__(
                                'Nothing here yet. Your first summary arrives a few days after your first full month.',
                            )}
                        </EmptyStateDescription>
                    </EmptyState>
                ) : (
                    <Card size="flush">
                        <ul className="divide-y overflow-hidden rounded-[inherit]">
                            {summaries.map((summary) => (
                                <li key={summary.id} data-testid="summary-row">
                                    <Link
                                        href={show(summary.id).url}
                                        className="flex items-center gap-4 p-4 transition-colors hover:bg-accent"
                                    >
                                        <div className="flex flex-1 flex-col gap-0.5">
                                            <span className="font-medium capitalize">
                                                {monthLabel(
                                                    summary.period,
                                                    locale,
                                                )}
                                            </span>
                                            <span className="text-sm text-muted-foreground">
                                                {summary.payload.cashflow
                                                    ?.savings_rate !== undefined
                                                    ? __(':rate% saved', {
                                                          rate: summary.payload
                                                              .cashflow
                                                              .savings_rate,
                                                      })
                                                    : __('Summary')}
                                                {!summary.complete &&
                                                    ` · ${__('partial month')}`}
                                                {summary.shared &&
                                                    ` · ${__('shared')}`}
                                            </span>
                                        </div>
                                        <ChevronRightIcon className="size-4 text-muted-foreground" />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
