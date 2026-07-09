import {
    destroy as destroySpace,
    leave as leaveSpace,
    select as selectSpace,
    index as spacesIndex,
    store as storeSpace,
    update as updateSpace,
} from '@/actions/App/Http/Controllers/SpaceController';
import HeadingSmall from '@/components/heading-small';
import {
    ManageMembers,
    type ManagedSpace,
} from '@/components/spaces/manage-members';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type SharedData, type Space } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import { Building2, Check, User } from 'lucide-react';
import { FormEvent, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Spaces settings',
        href: spacesIndex().url,
    },
];

export default function Spaces() {
    const { spaces, currentSpace } = usePage<SharedData>().props;
    const { managedSpaces, seatsInUse, maxSeats } = usePage<{
        managedSpaces: ManagedSpace[];
        seatsInUse: number;
        maxSeats: number;
    }>().props;
    const [createOpen, setCreateOpen] = useState(false);
    const [renaming, setRenaming] = useState<Space | null>(null);
    const [name, setName] = useState('');
    const [processing, setProcessing] = useState(false);

    const submitCreate = (event: FormEvent) => {
        event.preventDefault();
        setProcessing(true);
        router.post(
            storeSpace().url,
            { name },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setCreateOpen(false);
                    setName('');
                },
            },
        );
    };

    const submitRename = (event: FormEvent) => {
        event.preventDefault();
        if (!renaming) {
            return;
        }
        setProcessing(true);
        router.patch(
            updateSpace(renaming.id).url,
            { name },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onSuccess: () => setRenaming(null),
            },
        );
    };

    const remove = (space: Space) => {
        if (
            !window.confirm(
                __('Delete this space? This cannot be undone.') +
                    ` (${space.name})`,
            )
        ) {
            return;
        }
        router.delete(destroySpace(space.id).url, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Spaces')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex items-start justify-between gap-4">
                        <HeadingSmall
                            title={__('Spaces')}
                            description={__(
                                'Each space keeps its own accounts, transactions, categories and budgets separate.',
                            )}
                        />
                        <Button onClick={() => setCreateOpen(true)}>
                            {__('Create space')}
                        </Button>
                    </div>

                    <ul className="divide-y divide-border rounded-lg border border-border">
                        {spaces.map((space) => {
                            const isCurrent = space.id === currentSpace?.id;

                            return (
                                <li
                                    key={space.id}
                                    className="flex items-center gap-3 px-4 py-3"
                                    data-test="space-row"
                                >
                                    <div className="flex size-9 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                        {space.personal ? (
                                            <User className="size-4" />
                                        ) : (
                                            <Building2 className="size-4" />
                                        )}
                                    </div>
                                    <div className="flex-1">
                                        <div className="flex items-center gap-2 font-medium">
                                            {space.name}
                                            {isCurrent && (
                                                <span className="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary">
                                                    <Check className="size-3" />
                                                    {__('Active')}
                                                </span>
                                            )}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {space.personal
                                                ? __('Personal space')
                                                : __('Space')}
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        {!isCurrent && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        selectSpace(space.id)
                                                            .url,
                                                    )
                                                }
                                            >
                                                {__('Switch')}
                                            </Button>
                                        )}
                                        {!space.personal && space.is_owner && (
                                            <>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => {
                                                        setName(space.name);
                                                        setRenaming(space);
                                                    }}
                                                >
                                                    {__('Rename')}
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-destructive hover:text-destructive"
                                                    onClick={() =>
                                                        remove(space)
                                                    }
                                                >
                                                    {__('Delete')}
                                                </Button>
                                            </>
                                        )}
                                        {!space.personal && !space.is_owner && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-destructive hover:text-destructive"
                                                onClick={() =>
                                                    router.post(
                                                        leaveSpace(space.id)
                                                            .url,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {__('Leave')}
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>

                    <ManageMembers
                        managedSpaces={managedSpaces}
                        seatsInUse={seatsInUse}
                        maxSeats={maxSeats}
                    />
                </div>
            </SettingsLayout>

            <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                <DialogContent>
                    <form onSubmit={submitCreate}>
                        <DialogHeader>
                            <DialogTitle>{__('Create space')}</DialogTitle>
                            <DialogDescription>
                                {__(
                                    'A space groups its own accounts, transactions, categories and budgets, kept separate from your other spaces.',
                                )}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2 py-4">
                            <Label htmlFor="create-space-name">
                                {__('Name')}
                            </Label>
                            <Input
                                id="create-space-name"
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                placeholder={__('e.g. My Company')}
                                autoFocus
                                required
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCreateOpen(false)}
                            >
                                {__('Cancel')}
                            </Button>
                            <Button
                                type="submit"
                                disabled={processing || name.trim() === ''}
                            >
                                {__('Create space')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={renaming !== null}
                onOpenChange={(open) => !open && setRenaming(null)}
            >
                <DialogContent>
                    <form onSubmit={submitRename}>
                        <DialogHeader>
                            <DialogTitle>{__('Rename space')}</DialogTitle>
                        </DialogHeader>
                        <div className="grid gap-2 py-4">
                            <Label htmlFor="rename-space-name">
                                {__('Name')}
                            </Label>
                            <Input
                                id="rename-space-name"
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                autoFocus
                                required
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setRenaming(null)}
                            >
                                {__('Cancel')}
                            </Button>
                            <Button
                                type="submit"
                                disabled={processing || name.trim() === ''}
                            >
                                {__('Save')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
