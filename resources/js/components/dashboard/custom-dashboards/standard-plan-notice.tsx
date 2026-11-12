import { ProBadge } from '@/components/pro-badge';
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
    trackUpgradeClicked,
    trackUpgradePrompted,
    type UpgradePromptTrigger,
} from '@/lib/custom-dashboards-telemetry';
import { subscribe } from '@/routes';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { useEffect } from 'react';

function SeePlansButton({ trigger }: { trigger: UpgradePromptTrigger }) {
    return (
        <Button asChild>
            <Link
                href={subscribe().url}
                onClick={() => trackUpgradeClicked(trigger)}
            >
                {__('See plans')}
            </Link>
        </Button>
    );
}

/**
 * Over a dashboard the user built while on the paid plan: it is kept, and
 * comes back as it was once they are on Standard again.
 */
export function LockedDashboardNotice() {
    useEffect(() => trackUpgradePrompted('locked_dashboard'), []);

    return (
        <div
            role="status"
            data-testid="locked-dashboard-notice"
            className="flex max-w-md flex-col items-center gap-3 rounded-xl border bg-card px-6 py-8 text-center text-card-foreground shadow-lg"
        >
            <ProBadge />
            <p className="text-lg font-semibold">
                {__('Your dashboard is waiting for you')}
            </p>
            <p className="text-sm text-muted-foreground">
                {__(
                    'Custom dashboards and the widgets your agent builds are part of the Standard plan. Everything you set up is kept, and comes back as it was when you upgrade.',
                )}
            </p>
            <SeePlansButton trigger="locked_dashboard" />
        </div>
    );
}

/** What "New dashboard" opens without the paid plan. */
export function StandardPlanDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    useEffect(() => {
        if (open) {
            trackUpgradePrompted('new_dashboard');
        }
    }, [open]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        {__('New dashboard')}
                        <ProBadge />
                    </DialogTitle>
                    <DialogDescription>
                        {__(
                            'Building your own dashboards, and the widgets your agent writes for them, is part of the Standard plan.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        {__('Not now')}
                    </Button>
                    <SeePlansButton trigger="new_dashboard" />
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
