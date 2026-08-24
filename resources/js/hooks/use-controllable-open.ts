import { useState } from 'react';

interface Options {
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}

/**
 * Lets a dialog work both controlled (parent passes open/onOpenChange) and
 * uncontrolled (own internal state) without each component reimplementing the
 * branching.
 */
export function useControllableOpen({ open, onOpenChange }: Options) {
    const [internalOpen, setInternalOpen] = useState(false);
    const isControlled = open !== undefined;

    const setOpen = (next: boolean) => {
        if (isControlled) {
            onOpenChange?.(next);
        } else {
            setInternalOpen(next);
        }
    };

    return {
        open: isControlled ? open : internalOpen,
        setOpen,
        isControlled,
    };
}
