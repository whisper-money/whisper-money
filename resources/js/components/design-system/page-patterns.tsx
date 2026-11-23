import {
    Specimen,
    type PreviewEntry,
} from '@/components/design-system/showcase';
import Heading from '@/components/heading';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { AdaptivePopover } from '@/components/ui/adaptive-popover';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    EmptyState,
    EmptyStateContent,
    EmptyStateDescription,
    EmptyStateHeader,
    EmptyStateMedia,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { LayoutGrid, Plus } from 'lucide-react';
import { useState } from 'react';

function Headings() {
    return (
        <div className="flex w-full flex-col gap-6">
            <Specimen label="Heading">
                <Heading
                    title="Profile information"
                    description="Update your name and email address"
                />
            </Specimen>
            <Specimen label="HeadingSmall">
                <HeadingSmall
                    title="Delete account"
                    description="Delete your account and all of its data"
                />
            </Specimen>
        </div>
    );
}

const SKELETON_ROWS = [1, 2, 3];

function Skeletons() {
    return (
        <div className="flex w-full flex-col gap-3">
            {SKELETON_ROWS.map((row) => (
                <div key={row} className="flex items-center gap-3">
                    <Skeleton className="size-8 rounded-full" />
                    <div className="flex flex-1 flex-col gap-1.5">
                        <Skeleton className="h-4 w-1/3" />
                        <Skeleton className="h-3 w-1/5" />
                    </div>
                    <Skeleton className="h-4 w-16" />
                </div>
            ))}
        </div>
    );
}

function Forms() {
    return (
        <form
            className="flex w-full max-w-md flex-col gap-6"
            onSubmit={(event) => event.preventDefault()}
        >
            <div className="grid gap-2">
                <Label htmlFor="ds-form-name">Name</Label>
                <Input id="ds-form-name" defaultValue="Ada" />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="ds-form-email">Email address</Label>
                <Input
                    id="ds-form-email"
                    type="email"
                    defaultValue="ada@"
                    aria-invalid
                />
                <InputError message="The email field must be a valid email address." />
            </div>
            <Button type="submit" className="w-fit">
                Save
            </Button>
        </form>
    );
}

function EmptyStates() {
    return (
        <div className="grid w-full gap-6 md:grid-cols-2">
            <Specimen label="EmptyState: page, with icon, title and action">
                <EmptyState>
                    <EmptyStateHeader>
                        <EmptyStateMedia>
                            <LayoutGrid />
                        </EmptyStateMedia>
                        <EmptyStateTitle>
                            This dashboard is empty
                        </EmptyStateTitle>
                        <EmptyStateDescription>
                            Add the widgets you want to see here, then drag them
                            into place.
                        </EmptyStateDescription>
                    </EmptyStateHeader>
                    <EmptyStateContent>
                        <Button>
                            <Plus />
                            Add widget
                        </Button>
                    </EmptyStateContent>
                </EmptyState>
            </Specimen>
            <Specimen label="EmptyState: page, one line">
                <EmptyState>
                    <EmptyStateDescription>
                        Nothing here yet. Updates about your money show up here
                        as they happen.
                    </EmptyStateDescription>
                </EmptyState>
            </Specimen>
            <Specimen label='EmptyState size="inline", in a Card'>
                <Card>
                    <CardHeader>
                        <CardTitle>Top spending categories</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <EmptyState size="inline">
                            <EmptyStateDescription>
                                No spending data this month
                            </EmptyStateDescription>
                        </EmptyState>
                    </CardContent>
                </Card>
            </Specimen>
            <Specimen label='EmptyState size="inline", title and icon tile'>
                <EmptyState size="inline">
                    <EmptyStateHeader>
                        <EmptyStateMedia variant="icon">
                            <LayoutGrid />
                        </EmptyStateMedia>
                        <EmptyStateTitle>No transactions</EmptyStateTitle>
                        <EmptyStateDescription>
                            Nothing matches the current filters.
                        </EmptyStateDescription>
                    </EmptyStateHeader>
                </EmptyState>
            </Specimen>
        </div>
    );
}

function AdaptivePopoverPreview() {
    const [open, setOpen] = useState(false);

    return (
        <AdaptivePopover
            open={open}
            onOpenChange={setOpen}
            title="Notifications"
            align="start"
            trigger={<Button variant="outline">AdaptivePopover</Button>}
        >
            <p className="p-4 text-sm">Sample panel.</p>
        </AdaptivePopover>
    );
}

export const PAGE_PATTERN_PREVIEWS: PreviewEntry[] = [
    { id: 'headings', title: 'Headings', Component: Headings },
    { id: 'empty-states', title: 'Empty states', Component: EmptyStates },
    { id: 'skeletons', title: 'Skeletons', Component: Skeletons },
    { id: 'forms', title: 'Forms', Component: Forms },
    {
        id: 'adaptive-popover',
        title: 'Adaptive popover',
        Component: AdaptivePopoverPreview,
    },
];
