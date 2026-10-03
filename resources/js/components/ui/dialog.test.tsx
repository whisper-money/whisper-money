import { Shortcut } from '@/components/shortcuts/shortcut';
import { useShortcut } from '@/hooks/use-shortcut';
import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from './dialog';

afterEach(() => {
    vi.restoreAllMocks();
});

function pressN() {
    fireEvent.keyDown(document.activeElement ?? document.body, { key: 'n' });
}

/** A page with an `n` of its own, around whatever dialog it renders. */
function Page({
    onPageKey,
    children,
}: {
    onPageKey: () => void;
    children: React.ReactNode;
}) {
    useShortcut('transaction-dialog.add-note', onPageKey);

    return <>{children}</>;
}

function DialogWithShortcut({
    open,
    onDialogKey,
    children,
}: {
    open?: boolean;
    onDialogKey: () => void;
    children?: React.ReactNode;
}) {
    return (
        <Dialog open={open}>
            <DialogTrigger>Open</DialogTrigger>
            <DialogContent>
                <DialogTitle>Edit</DialogTitle>
                <DialogDescription>Edit it</DialogDescription>
                <Shortcut
                    id="transaction-dialog.add-note"
                    onTrigger={onDialogKey}
                />
                {children}
            </DialogContent>
        </Dialog>
    );
}

describe('DialogContent', () => {
    it('pins its grid column so long content cannot widen the dialog', () => {
        render(
            <Dialog open>
                <DialogContent>
                    <DialogTitle>Edit</DialogTitle>
                    <div className="whitespace-nowrap">
                        A description far longer than any phone screen can fit
                        without wrapping anywhere at all
                    </div>
                </DialogContent>
            </Dialog>,
        );

        // The dialog is a grid, and an implicit `auto` column is sized by the
        // min-content of its children - non-wrapping content then stretched the
        // whole dialog past the viewport and gave it a horizontal scrollbar.
        // `grid-cols-1` is `minmax(0, 1fr)`, which pins the track to the
        // container. jsdom has no layout, so the class is what we can assert.
        expect(
            document.querySelector('[data-slot="dialog-content"]'),
        ).toHaveClass('grid-cols-1');
    });
});

describe('Dialog as a shortcut layer', () => {
    it('takes the keys while open, and the page gets them back once closed', () => {
        const onPageKey = vi.fn();
        const onDialogKey = vi.fn();
        const { rerender } = render(
            <Page onPageKey={onPageKey}>
                <DialogWithShortcut open onDialogKey={onDialogKey} />
            </Page>,
        );

        pressN();
        expect(onDialogKey).toHaveBeenCalledOnce();
        expect(onPageKey).not.toHaveBeenCalled();

        rerender(
            <Page onPageKey={onPageKey}>
                <DialogWithShortcut open={false} onDialogKey={onDialogKey} />
            </Page>,
        );
        pressN();
        expect(onPageKey).toHaveBeenCalledOnce();
        expect(onDialogKey).toHaveBeenCalledOnce();
    });

    it('stops its shortcuts as soon as it starts closing', () => {
        // Radix keeps the content mounted until its exit animation ends. jsdom
        // runs no CSS, so give every element Radix animates an animation name
        // that changes when it closes, which is what Radix waits on.
        const getComputedStyle = window.getComputedStyle.bind(window);
        vi.spyOn(window, 'getComputedStyle').mockImplementation((element) => {
            const styles = getComputedStyle(element);
            const state = element.getAttribute('data-state');

            if (state === null) {
                return styles;
            }

            return new Proxy(styles, {
                get: (target, property) =>
                    property === 'animationName'
                        ? element.getAttribute('data-state')
                        : Reflect.get(target, property),
            });
        });
        const onPageKey = vi.fn();
        const onDialogKey = vi.fn();
        const { rerender } = render(
            <Page onPageKey={onPageKey}>
                <DialogWithShortcut open onDialogKey={onDialogKey} />
            </Page>,
        );

        rerender(
            <Page onPageKey={onPageKey}>
                <DialogWithShortcut open={false} onDialogKey={onDialogKey} />
            </Page>,
        );
        expect(screen.getByRole('dialog')).toHaveAttribute(
            'data-state',
            'closed',
        );

        pressN();

        expect(onDialogKey).not.toHaveBeenCalled();
        expect(onPageKey).toHaveBeenCalledOnce();
    });

    it('hands the keys to a dialog opened inside it, then takes them back', () => {
        const onOuterKey = vi.fn();
        const onInnerKey = vi.fn();
        const { rerender } = render(
            <DialogWithShortcut open onDialogKey={onOuterKey}>
                <DialogWithShortcut open onDialogKey={onInnerKey} />
            </DialogWithShortcut>,
        );

        pressN();
        expect(onInnerKey).toHaveBeenCalledOnce();
        expect(onOuterKey).not.toHaveBeenCalled();

        rerender(
            <DialogWithShortcut open onDialogKey={onOuterKey}>
                <DialogWithShortcut open={false} onDialogKey={onInnerKey} />
            </DialogWithShortcut>,
        );
        pressN();
        expect(onOuterKey).toHaveBeenCalledOnce();
    });

    it('follows a dialog its trigger opens and Escape closes', () => {
        const onPageKey = vi.fn();
        const onDialogKey = vi.fn();

        render(
            <Page onPageKey={onPageKey}>
                <DialogWithShortcut onDialogKey={onDialogKey} />
            </Page>,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Open' }));
        pressN();
        expect(onDialogKey).toHaveBeenCalledOnce();
        expect(onPageKey).not.toHaveBeenCalled();

        fireEvent.keyDown(document.activeElement!, { key: 'Escape' });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        pressN();
        expect(onPageKey).toHaveBeenCalledOnce();
    });
});
