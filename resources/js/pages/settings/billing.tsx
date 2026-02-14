import HeadingSmall from '@/components/heading-small';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { billing } from '@/routes/settings';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, usePage } from '@inertiajs/react';
import {
    CheckIcon,
    CreditCardIcon,
    InfinityIcon,
    InfoIcon,
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
        title: 'Privacy First',
        description:
            'Your data is never shared with third parties. You are always the owner.',
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
    const { auth } = usePage<SharedData>().props;
    const isDemoAccount = auth?.isDemoAccount ?? false;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Manage Plan')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title={__('Your Pro Plan')}
                        description={__(
                            "You're enjoying all the benefits of Whisper Money Pro",
                        )}
                    />

                    {isDemoAccount && (
                        <Alert>
                            <InfoIcon className="h-4 w-4" />
                            <AlertDescription>
                                {__(
                                    'Billing management is not available on the demo\n                                account.',
                                )}
                            </AlertDescription>
                        </Alert>
                    )}

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
                            <span className="font-medium">
                                {__('Pro Plan Active')}
                            </span>
                            <span className="text-muted-foreground">
                                {__('\u2014 $9/month')}
                            </span>
                        </div>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {__(
                                'Manage your subscription, update payment methods, or\n                            view invoices through the Stripe billing portal.',
                            )}
                        </p>
                        {!isDemoAccount && (
                            <a href={billing.portal.url()}>
                                <Button className="mt-4">
                                    <CreditCardIcon className="size-4" />
                                    {__('Manage Subscription')}
                                </Button>
                            </a>
                        )}
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
