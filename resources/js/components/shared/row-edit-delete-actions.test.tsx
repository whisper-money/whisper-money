import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    type DialogControl,
    RowActionsDropdown,
} from './row-edit-delete-actions';

const dialog = (name: string) =>
    function renderDialog({ open }: DialogControl) {
        return open ? <div role="dialog">{name}</div> : null;
    };

async function openMenu() {
    fireEvent.pointerDown(screen.getByRole('button', { name: 'Open menu' }), {
        button: 0,
        ctrlKey: false,
    });

    return (await screen.findAllByRole('menuitem')).map(
        (item) => item.textContent,
    );
}

describe('RowActionsDropdown', () => {
    it('lists only Edit and Delete when the page adds no actions', async () => {
        render(
            <RowActionsDropdown
                renderEditDialog={dialog('edit')}
                renderDeleteDialog={dialog('delete')}
            />,
        );

        expect(await openMenu()).toEqual(['Edit', 'Delete']);
    });

    it('lists extra actions before Delete and opens only the chosen one', async () => {
        render(
            <RowActionsDropdown
                renderEditDialog={dialog('edit')}
                renderDeleteDialog={dialog('delete')}
                extraActions={[
                    { label: 'Duplicate', renderDialog: dialog('duplicate') },
                ]}
            />,
        );

        expect(await openMenu()).toEqual(['Edit', 'Duplicate', 'Delete']);

        fireEvent.click(screen.getByRole('menuitem', { name: 'Duplicate' }));

        expect(screen.getByRole('dialog')).toHaveTextContent('duplicate');
    });
});
