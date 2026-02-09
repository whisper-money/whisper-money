import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { BankingConnection } from '@/types/banking';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';

interface DisconnectDialogProps {
    connection: BankingConnection;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function DisconnectDialog({
    connection,
    open,
    onOpenChange,
}: DisconnectDialogProps) {
    const [isSubmitting, setIsSubmitting] = useState(false);

    function handleDisconnect() {
        setIsSubmitting(true);

        router.delete(`/settings/connections/${connection.id}`, {
            onFinish: () => {
                setIsSubmitting(false);
                onOpenChange(false);
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>{__('Disconnect Bank')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'This will revoke access to your bank account data from :bank. Your existing transactions will not be deleted.',
                            { bank: connection.aspsp_name },
                        )}
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={isSubmitting}
                    >
                        {__('Cancel')}
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={handleDisconnect}
                        disabled={isSubmitting}
                    >
                        {isSubmitting
                            ? __('Disconnecting...')
                            : __('Disconnect')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
