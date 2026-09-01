import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Dialog, DialogContent, DialogTitle } from './dialog';

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
