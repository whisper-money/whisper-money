import { index } from '@/actions/App/Http/Controllers/PersonalSubscriptionController'
import HeadingSmall from '@/components/heading-small';
import { CreateSubscriptionDialog } from '@/components/pers-subs/create-subscription-dialog';
import { SubscriptionListCard } from '@/components/pers-subs/subscription-list-card';
import { CreateButton } from '@/components/ui/create-button';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { BreadcrumbItem } from '@/types';
import { PersonalSubscription } from '@/types/personal-subscriptions';
import { __ } from '@/utils/i18n';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Subscriptions',
        href: index().url,
    }
];

interface Props {
    personalSubs: PersonalSubscription[];
    currencyCode: string;
}

export default function PersonalSubsIndex({ personalSubs, currencyCode }: Props) {
    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Personal Subscriptions')} />

            <div className='space-y-8 p-6'>
                <div className='flex items-center justify-between gap-2'>
                    <HeadingSmall
                        title={__('Personal Subscriptions')}
                        description={__(
                            'Track your subsciptions'
                        )}
                    />
                    <CreateSubscriptionDialog
                        currencyCode={currencyCode}
                        trigger={
                            <CreateButton>{__('Create subscription')}</CreateButton>
                        }
                    />
                </div>

                {personalSubs.length > 0 ? (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {personalSubs.map((personalSubs) => (
                            <SubscriptionListCard
                                key={personalSubs.id}
                                subscription={personalSubs}
                                currencyCode={currencyCode}
                            />
                        ))}
                    </div>
                ) : (
                    <div className='grip gap-4 lg:grid-cols-2'>
                        <CreateSubscriptionDialog
                            className='min-h-[260px]'
                            currencyCode={currencyCode}
                        />
                    </div>
                )}
            </div>
        </AppSidebarLayout>
    )
}