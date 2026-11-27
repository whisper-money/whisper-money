import { index as integrationRequestsIndex } from '@/actions/App/Http/Controllers/Admin/IntegrationRequestController';
import { index } from '@/actions/App/Http/Controllers/AdminController';
import {
    IntegrationRequestList,
    type AdminIntegrationRequest,
} from '@/components/admin/integration-request-list';
import { PageHeader } from '@/components/page-header';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { __ } from '@/utils/i18n';
import { Head } from '@inertiajs/react';

interface Props {
    integrationRequests: AdminIntegrationRequest[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: index().url },
    {
        title: __('Integration requests'),
        href: integrationRequestsIndex().url,
    },
];

/** Every integration request, whatever its status, most voted first. */
export default function AdminIntegrationRequests({
    integrationRequests,
}: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Integration requests')} />

            <div className="flex flex-col gap-8 p-6">
                <PageHeader
                    title={__('Integration requests')}
                    description={__(':count requests, most voted first.', {
                        count: integrationRequests.length.toLocaleString(),
                    })}
                />

                <section>
                    <IntegrationRequestList
                        requests={integrationRequests}
                        filterable
                    />
                </section>
            </div>
        </AppLayout>
    );
}
