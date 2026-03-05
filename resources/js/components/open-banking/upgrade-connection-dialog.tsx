import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { billing } from '@/routes/settings';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { Zap } from 'lucide-react';

interface UpgradeConnectionDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function UpgradeConnectionDialog({
    open,
    onOpenChange,
}: UpgradeConnectionDialogProps) {
    function handleUpgrade() {
        router.visit(billing.url());
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[400px]">
                <DialogHeader>
                    <div className="mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/40">
                        <Zap className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                    </div>
                    <DialogTitle>{__('Standard Plan required')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'Bank connections automatically sync your transactions directly from your bank. This feature requires the Standard Plan.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        {__('Maybe later')}
                    </Button>
                    <Button type="button" onClick={handleUpgrade}>
                        {__('Upgrade to Standard Plan')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
