import { ProBadge } from '@/components/pro-badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { dashboard as dashboardRoute } from '@/routes';
import { type DashboardSummary } from '@/types/dashboard';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import {
    Check,
    ChevronDown,
    LayoutDashboard,
    Plus,
    Settings2,
    Star,
} from 'lucide-react';

/** The `?dashboard=` value that opens the system dashboard. */
export const SYSTEM_DASHBOARD_QUERY = 'system';

/** The name the picker gives the dashboard every user has. */
export function systemDashboardName(): string {
    return __('Overview');
}

export function openDashboard(id: string | null): void {
    router.visit(
        dashboardRoute.url({
            query: { dashboard: id ?? SYSTEM_DASHBOARD_QUERY },
        }),
    );
}

interface Props {
    dashboards: DashboardSummary[];
    /** The dashboard on screen; null is the system one. */
    selectedId: string | null;
    selectedName: string;
    defaultId: string | null;
    /** Building dashboards is part of the paid plan. */
    canCreate: boolean;
    canManage: boolean;
    onCreate: () => void;
    onManage: () => void;
}

/** Switches between the system dashboard and the user's own ones. */
export function DashboardPicker({
    dashboards,
    selectedId,
    selectedName,
    defaultId,
    canCreate,
    canManage,
    onCreate,
    onManage,
}: Props) {
    const entries: { id: string | null; name: string }[] = [
        { id: null, name: systemDashboardName() },
        ...dashboards,
    ];

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className="max-w-full min-w-0"
                    aria-label={__('Switch dashboard')}
                >
                    <LayoutDashboard className="size-4" />
                    <span className="truncate">{selectedName}</span>
                    <ChevronDown className="size-4 opacity-60" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
                {entries.map((entry) => (
                    <DropdownMenuItem
                        key={entry.id ?? SYSTEM_DASHBOARD_QUERY}
                        onSelect={() => openDashboard(entry.id)}
                    >
                        <Check
                            className={
                                entry.id === selectedId
                                    ? 'size-4'
                                    : 'size-4 opacity-0'
                            }
                        />
                        <span className="min-w-0 flex-1 truncate">
                            {entry.name}
                        </span>
                        {entry.id === defaultId && (
                            <Star
                                className="size-3.5 fill-amber-400 text-amber-400"
                                aria-label={__('Default')}
                            />
                        )}
                    </DropdownMenuItem>
                ))}
                {canManage && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            disabled={!canCreate}
                            onSelect={onCreate}
                        >
                            <Plus className="size-4" />
                            <span className="flex-1">
                                {__('New dashboard')}
                            </span>
                            {!canCreate && <ProBadge />}
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={onManage}>
                            <Settings2 className="size-4" />
                            {__('Manage dashboards')}
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
