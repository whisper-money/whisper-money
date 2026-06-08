import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { dashboard } from '@/routes';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Props = {
    open: boolean;
    delayInMs?: number;
};

export default function AuthenticatedRedirectDialog({
    open,
    delayInMs = 3000,
}: Props) {
    const [isOpen, setIsOpen] = useState(open);
    const [isCancelled, setIsCancelled] = useState(false);
    const [progress, setProgress] = useState(0);

    useEffect(() => {
        setIsOpen(open);
        setIsCancelled(false);
        setProgress(0);
    }, [open]);

    useEffect(() => {
        if (!isOpen || isCancelled) {
            return;
        }

        const startedAt = Date.now();
        const progressIntervalId = window.setInterval(() => {
            const elapsedInMs = Date.now() - startedAt;

            setProgress(
                Math.min(100, Math.round((elapsedInMs / delayInMs) * 100)),
            );
        }, 50);
        const redirectTimeoutId = window.setTimeout(() => {
            setProgress(100);
            router.visit(dashboard());
        }, delayInMs);

        return () => {
            window.clearInterval(progressIntervalId);
            window.clearTimeout(redirectTimeoutId);
        };
    }, [delayInMs, isCancelled, isOpen]);

    function cancelRedirect(): void {
        setIsCancelled(true);
        setIsOpen(false);
    }

    function redirectNow(): void {
        setProgress(100);
        router.visit(dashboard());
    }

    return (
        <Dialog open={isOpen}>
            <DialogContent showCloseButton={false}>
                <DialogHeader>
                    <DialogTitle>
                        {__('Redirecting to your dashboard')}
                    </DialogTitle>
                    <DialogDescription>
                        {__('You are being redirected in 3 seconds.')}
                    </DialogDescription>
                </DialogHeader>
                <div
                    role="progressbar"
                    aria-label={__('Redirect progress')}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={progress}
                    className="h-2 overflow-hidden rounded-full bg-secondary"
                >
                    <div
                        className="h-full rounded-full bg-primary transition-[width] duration-75 ease-linear motion-reduce:transition-none"
                        style={{ width: `${progress}%` }}
                    />
                </div>
                <DialogFooter className="sm:justify-between">
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={cancelRedirect}
                    >
                        {__('Cancel')}
                    </Button>
                    <Button type="button" onClick={redirectNow}>
                        {__('Go now')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
