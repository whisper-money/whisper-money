import { Shortcut } from '@/components/shortcuts/shortcut';
import {
    AlertDialog,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Drawer,
    DrawerContent,
    DrawerDescription,
    DrawerTitle,
} from '@/components/ui/drawer';
import { useShortcut } from '@/hooks/use-shortcut';
import { fireEvent, render } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

function pressN() {
    fireEvent.keyDown(document.activeElement ?? document.body, { key: 'n' });
}

function PageShortcut({ onTrigger }: { onTrigger: () => void }) {
    useShortcut('transaction-dialog.add-note', onTrigger);

    return null;
}

describe('the modal primitives as shortcut layers', () => {
    it('puts the page to sleep while an alert dialog is open', () => {
        const onPageKey = vi.fn();
        const { rerender } = render(
            <>
                <PageShortcut onTrigger={onPageKey} />
                <AlertDialog open>
                    <AlertDialogContent>
                        <AlertDialogTitle>Delete?</AlertDialogTitle>
                        <AlertDialogDescription>Sure?</AlertDialogDescription>
                    </AlertDialogContent>
                </AlertDialog>
            </>,
        );

        pressN();
        expect(onPageKey).not.toHaveBeenCalled();

        rerender(
            <>
                <PageShortcut onTrigger={onPageKey} />
                <AlertDialog open={false} />
            </>,
        );
        pressN();
        expect(onPageKey).toHaveBeenCalledOnce();
    });

    it('stops a dialog’s shortcuts while an alert dialog sits on top of it', () => {
        const onDialogKey = vi.fn();

        render(
            <Dialog open>
                <DialogContent>
                    <DialogTitle>Edit</DialogTitle>
                    <DialogDescription>Edit it</DialogDescription>
                    <Shortcut
                        id="transaction-dialog.add-note"
                        onTrigger={onDialogKey}
                    />
                    <AlertDialog open>
                        <AlertDialogContent>
                            <AlertDialogTitle>Discard?</AlertDialogTitle>
                            <AlertDialogDescription>
                                Sure?
                            </AlertDialogDescription>
                        </AlertDialogContent>
                    </AlertDialog>
                </DialogContent>
            </Dialog>,
        );

        pressN();

        expect(onDialogKey).not.toHaveBeenCalled();
    });

    it('gives the keys to an open drawer', () => {
        const onPageKey = vi.fn();
        const onDrawerKey = vi.fn();

        render(
            <>
                <PageShortcut onTrigger={onPageKey} />
                <Drawer open>
                    <DrawerContent>
                        <DrawerTitle>Import</DrawerTitle>
                        <DrawerDescription>Pick a file</DrawerDescription>
                        <Shortcut
                            id="transaction-dialog.add-note"
                            onTrigger={onDrawerKey}
                        />
                    </DrawerContent>
                </Drawer>
            </>,
        );

        pressN();

        expect(onDrawerKey).toHaveBeenCalledOnce();
        expect(onPageKey).not.toHaveBeenCalled();
    });
});
