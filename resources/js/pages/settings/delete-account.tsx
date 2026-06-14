import DeleteUser from '@/components/delete-user';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as deleteAccountEdit } from '@/routes/delete-account';
import { type BreadcrumbItem } from '@/types';
import { __ } from '@/utils/i18n';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Delete account',
        href: deleteAccountEdit().url,
    },
];

export default function DeleteAccount({
    hasActiveSubscriptionOrTrial,
}: {
    hasActiveSubscriptionOrTrial: boolean;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Delete account')} />

            <SettingsLayout>
                <DeleteUser
                    hasActiveSubscriptionOrTrial={hasActiveSubscriptionOrTrial}
                />
            </SettingsLayout>
        </AppLayout>
    );
}
