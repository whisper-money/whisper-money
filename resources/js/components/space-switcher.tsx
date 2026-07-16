import {
    index as manageSpaces,
    select as selectSpace,
    store as storeSpace,
} from '@/actions/App/Http/Controllers/SpaceController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useIsMobile } from '@/hooks/use-mobile';
import { SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Building2,
    Check,
    ChevronsUpDown,
    Plus,
    Settings2,
    User,
} from 'lucide-react';
import { FormEvent, useState } from 'react';

export function SpaceSwitcher() {
    const { currentSpace, spaces, features } = usePage<SharedData>().props;
    const { state } = useSidebar();
    const isMobile = useIsMobile();
    const [createOpen, setCreateOpen] = useState(false);
    const [name, setName] = useState('');
    const [processing, setProcessing] = useState(false);

    // The switcher only exists for accounts with the spaces feature; everyone
    // else keeps their single, invisible personal space.
    if (!features.spaces || !currentSpace) {
        return null;
    }

    const switchTo = (spaceId: string) => {
        if (spaceId === currentSpace.id) {
            return;
        }

        router.post(selectSpace(spaceId).url, {}, { preserveScroll: false });
    };

    const createSpace = (event: FormEvent) => {
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

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="data-[state=open]:bg-sidebar-accent"
                            data-test="space-switcher"
                        >
                            <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary/10 text-sidebar-primary">
                                {currentSpace.personal ? (
                                    <User className="size-4" />
                                ) : (
                                    <Building2 className="size-4" />
                                )}
                            </div>
                            <div className="grid flex-1 text-left text-sm leading-tight">
                                <span className="truncate font-medium">
                                    {currentSpace.name}
                                </span>
                                <span className="truncate text-xs text-muted-foreground">
                                    {currentSpace.personal
                                        ? __('Personal space')
                                        : __('Space')}
                                </span>
                            </div>
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-60 rounded-lg"
                        align="start"
                        side={
                            isMobile
                                ? 'bottom'
                                : state === 'collapsed'
                                  ? 'right'
                                  : 'bottom'
                        }
                    >
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            {__('Spaces')}
                        </DropdownMenuLabel>
                        {spaces.map((space) => (
                            <DropdownMenuItem
                                key={space.id}
                                onClick={() => switchTo(space.id)}
                                className="gap-2"
                                data-test="space-option"
                            >
                                {space.personal ? (
                                    <User className="size-4 text-muted-foreground" />
                                ) : (
                                    <Building2 className="size-4 text-muted-foreground" />
                                )}
                                <span className="truncate">{space.name}</span>
                                {space.id === currentSpace.id && (
                                    <Check className="ml-auto size-4" />
                                )}
                            </DropdownMenuItem>
                        ))}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onClick={() => setCreateOpen(true)}
                            className="gap-2"
                            data-test="space-create"
                        >
                            <Plus className="size-4" />
                            {__('Create space')}
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild className="gap-2">
                            <Link href={manageSpaces().url}>
                                <Settings2 className="size-4" />
                                {__('Manage spaces')}
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>

            <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                <DialogContent>
                    <form onSubmit={createSpace}>
                        <DialogHeader>
                            <DialogTitle>{__('Create space')}</DialogTitle>
                            <DialogDescription>
                                {__(
                                    'A space groups its own accounts, transactions, categories and budgets, kept separate from your other spaces.',
                                )}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2 py-4">
                            <Label htmlFor="space-name">{__('Name')}</Label>
                            <Input
                                id="space-name"
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
        </SidebarMenu>
    );
}
