import { index } from '@/actions/App/Http/Controllers/PersonalSubscriptionController'
import HeadingSmall from '@/components/heading-small';
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
    currencyCode: String;
}

export default function PersonalSubsIndex({personalSubs, currencyCode}: Props) {
    return(
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
                </div>
            </div>
        </AppSidebarLayout>
    )
}