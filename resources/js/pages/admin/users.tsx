import { index as usersIndex } from '@/actions/App/Http/Controllers/Admin/UserController';
import { index } from '@/actions/App/Http/Controllers/AdminController';
import { AdminUserTable, type AdminUser } from '@/components/admin/user-table';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, Link } from '@inertiajs/react';

type Paginated<T> = {
    data: T[];
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

interface Props {
    users: Paginated<AdminUser>;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: index().url },
    { title: __('Users'), href: usersIndex().url },
];

/** 44px tap targets on a phone, `size="sm"` from `sm` up. */
const PAGER_HEIGHT = 'h-11 sm:h-8';

/** One end of the pager: a link while there is a page there, a dead button when not. */
function PageLink({ href, label }: { href: string | null; label: string }) {
    if (!href) {
        return (
            <Button
                variant="outline"
                size="sm"
                className={PAGER_HEIGHT}
                disabled
            >
                {label}
            </Button>
        );
    }

    return (
        <Button variant="outline" size="sm" className={PAGER_HEIGHT} asChild>
            <Link href={href}>{label}</Link>
        </Button>
    );
}

/** Every user, newest first, 50 to a page. */
export default function AdminUsers({ users }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Users')} />

            <div className="flex flex-col gap-8 p-6">
                <PageHeader
                    title={__('Users')}
                    description={__(':count users, newest first.', {
                        count: users.total.toLocaleString(),
                    })}
                />

                <section className="space-y-4">
                    <AdminUserTable users={users.data} />

                    <div className="flex items-center justify-between gap-4">
                        <span className="text-sm text-muted-foreground">
                            {__('Showing :from–:to of :total', {
                                from: users.from ?? 0,
                                to: users.to ?? 0,
                                total: users.total.toLocaleString(),
                            })}
                        </span>
                        <div className="flex gap-2">
                            <PageLink
                                href={users.prev_page_url}
                                label={__('Previous')}
                            />
                            <PageLink
                                href={users.next_page_url}
                                label={__('Next')}
                            />
                        </div>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
