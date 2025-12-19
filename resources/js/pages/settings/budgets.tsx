import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Budget settings',
        href: '/settings/budgets',
    },
];

export default function BudgetsSettings() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Budget settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Budget settings"
                        description="Manage your budgets and spending categories"
                    />

                    <div className="rounded-lg border p-6">
                        <div className="space-y-4">
                            <div>
                                <h3 className="text-lg font-medium">
                                    Manage Budgets
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    Create, edit, and delete your budgets. View
                                    spending history and analytics.
                                </p>
                            </div>
                            <Link href="/budgets">
                                <Button>
                                    Go to Budgets
                                    <ArrowRight className="ml-2 h-4 w-4" />
                                </Button>
                            </Link>
                        </div>
                    </div>

                    <div className="rounded-lg border p-6">
                        <div className="space-y-4">
                            <div>
                                <h3 className="text-lg font-medium">
                                    About Budgets
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    Budgets help you track your spending across
                                    different categories. You can create
                                    budgets with custom periods (monthly,
                                    weekly, bi-weekly, or custom) and choose
                                    how unused money is handled at the end of
                                    each period.
                                </p>
                            </div>
                            <ul className="list-inside list-disc space-y-2 text-sm text-muted-foreground">
                                <li>
                                    <strong>Carry Over:</strong> Unused money
                                    rolls over to the next period
                                </li>
                                <li>
                                    <strong>Reset/Pool:</strong> Unused money
                                    returns to your available money pool
                                </li>
                                <li>
                                    <strong>Zero-Based Budgeting:</strong>{' '}
                                    Assign every dollar a job
                                </li>
                                <li>
                                    <strong>Auto-Assignment:</strong>{' '}
                                    Transactions automatically link to budgets
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

