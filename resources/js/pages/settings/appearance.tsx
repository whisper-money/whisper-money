import { __ } from '@/utils/i18n';
import { Head } from '@inertiajs/react';

import AppearanceTabs from '@/components/appearance-tabs';
import HeadingSmall from '@/components/heading-small';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editAppearance } from '@/routes/appearance';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Appearance settings',
        href: editAppearance().url,
    },
];

export default function Appearance() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Appearance settings')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title={__('Appearance settings')}
                        description={__(
                            "Update your account's appearance settings",
                        )}
                    />

                    <AppearanceTabs />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
