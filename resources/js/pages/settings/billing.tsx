import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { billing } from '@/routes/settings';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import {
    CheckIcon,
    CreditCardIcon,
    InfinityIcon,
    ShieldCheckIcon,
    SparklesIcon,
} from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Manage Plan',
        href: billing().url,
    },
];

const benefits = [
    {
        icon: InfinityIcon,
        title: 'Unlimited Everything',
        description: 'No limits on bank accounts, transactions, or categories.',
    },
    {
        icon: ShieldCheckIcon,
        title: 'End-to-End Encryption',
        description:
            'Your financial data is encrypted with military-grade security.',
    },
    {
        icon: SparklesIcon,
        title: 'Smart Automation',
        description:
            'Automation rules to categorize transactions automatically.',
    },
    {
        icon: CreditCardIcon,
        title: 'Priority Support',
        description: 'Get help when you need it with priority email support.',
    },
];

export default function Billing() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Manage Plan" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Your Pro Plan"
                        description="You're enjoying all the benefits of Whisper Money Pro"
                    />

                    <div className="grid gap-4 sm:grid-cols-2">
                        {benefits.map((benefit) => (
                            <div
                                key={benefit.title}
                                className="flex items-start gap-3 rounded-lg border bg-card p-4"
                            >
                                <div className="flex size-9 shrink-0 items-center justify-center rounded-md bg-emerald-100 dark:bg-emerald-900/30">
                                    <benefit.icon className="size-5 text-emerald-600 dark:text-emerald-400" />
                                </div>
                                <div>
                                    <h3 className="font-medium">
                                        {benefit.title}
                                    </h3>
                                    <p className="text-sm text-muted-foreground">
                                        {benefit.description}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>

                    <div className="rounded-lg border bg-card p-5">
                        <div className="flex items-center gap-2">
                            <CheckIcon className="size-5 text-emerald-500" />
                            <span className="font-medium">Pro Plan Active</span>
                            <span className="text-muted-foreground">
                                — $9/month
                            </span>
                        </div>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Manage your subscription, update payment methods, or
                            view invoices through the Stripe billing portal.
                        </p>
                        <a href={billing.portal.url()}>
                            <Button className="mt-4">
                                <CreditCardIcon className="size-4" />
                                Manage Subscription
                            </Button>
                        </a>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
