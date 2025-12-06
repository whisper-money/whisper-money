import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { checkout } from '@/routes/subscribe';
import { SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { CheckIcon } from 'lucide-react';

export default function Paywall() {
    const { auth } = usePage<SharedData>().props;

    const isUserCreatedTwoDaysAgoOrBefore = (() => {
        if (!auth.user || !auth.user.created_at) {
            return false;
        }

        const createdAt = new Date(auth.user.created_at);
        const twoDaysAgo = new Date();
        twoDaysAgo.setDate(twoDaysAgo.getDate() - 2);

        return createdAt <= twoDaysAgo;
    })();

    return (
        <AuthLayout
            title="Upgrade to Pro"
            description="Unlock all features and take control of your finances"
        >
            <Head title="Upgrade to Pro" />

            <div className="flex flex-col gap-6">
                <div className="overflow-hidden rounded-xl border bg-card">
                    <div className="bg-gradient-to-br from-emerald-50 to-teal-50 px-5 py-4 dark:from-emerald-900/20 dark:to-teal-900/20">
                        <div className="flex items-baseline gap-2">
                            <span className="text-3xl font-bold">$9</span>
                            <span className="text-muted-foreground">/month</span>
                        </div>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Billed monthly. Cancel anytime.
                        </p>
                    </div>

                    <div className="p-5">
                        <ul className="space-y-2.5">
                            {[
                                'Unlimited bank accounts',
                                'Unlimited transactions',
                                'End-to-end encryption',
                                'Smart categorization',
                                'Automation rules',
                                'Visual insights & reports',
                                'Priority support',
                            ].map((feature) => (
                                <li
                                    key={feature}
                                    className="flex items-center gap-2.5"
                                >
                                    <CheckIcon className="size-4 shrink-0 text-emerald-500" />
                                    <span className="text-sm">{feature}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>

                {isUserCreatedTwoDaysAgoOrBefore && <p className="text-center text-xs text-muted-foreground">
                    Use code{' '}
                    <span className="font-mono font-semibold text-emerald-600 dark:text-emerald-400">
                        FOUNDER
                    </span>{' '}
                    for $8 off your first month
                </p>}

                <a href={checkout.url()}>
                    <Button className="w-full">Subscribe Now</Button>
                </a>
            </div>
        </AuthLayout>
    );
}
