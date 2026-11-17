import type { Label } from '@/types/label';
import { fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import LabelsPage from './labels';

globalThis.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
};

const page = vi.hoisted(() => ({ labels: [] as unknown[] }));
const patch = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => ({ props: { labels: page.labels } }),
    router: { patch },
    Form: ({
        children,
    }: {
        children: (args: {
            errors: Record<string, string>;
            processing: boolean;
        }) => ReactNode;
    }) => <form>{children({ errors: {}, processing: false })}</form>,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

function makeLabel(overrides: Partial<Label>): Label {
    return {
        id: 'label-1',
        user_id: 'user-1',
        name: 'Label',
        color: 'blue',
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
        deleted_at: null,
        archived_at: null,
        ...overrides,
    };
}

const archivedAt = '2026-02-01T00:00:00Z';

function rowNames(): string[] {
    return screen
        .getAllByRole('row')
        .slice(1)
        .map((row) => within(row).getAllByRole('cell')[0].textContent ?? '');
}

async function chooseRowAction(rowName: string, action: string) {
    const row = screen
        .getAllByRole('row')
        .find((candidate) => candidate.textContent?.includes(rowName))!;

    fireEvent.pointerDown(
        within(row).getByRole('button', { name: 'Open menu' }),
        { button: 0, ctrlKey: false },
    );
    fireEvent.click(await screen.findByRole('menuitem', { name: action }));
}

describe('Labels settings page', () => {
    beforeEach(() => {
        patch.mockClear();
        page.labels = [
            makeLabel({ id: 'zoo', name: 'Zoo' }),
            makeLabel({
                id: 'apart',
                name: 'Apartment',
                archived_at: archivedAt,
            }),
            makeLabel({ id: 'bills', name: 'Bills' }),
        ];
    });

    it('lists archived labels last, dimmed and marked as archived', () => {
        render(<LabelsPage />);

        expect(rowNames()).toEqual(['Bills', 'Zoo', 'ApartmentArchived']);

        const archivedName = screen.getByText('Apartment').parentElement!;
        expect(archivedName).toHaveClass('opacity-60');
        expect(screen.getByText('Bills').parentElement!).not.toHaveClass(
            'opacity-60',
        );
    });

    it('keeps archived labels last when the names are sorted descending', () => {
        render(<LabelsPage />);

        fireEvent.click(screen.getByRole('button', { name: 'Name' }));

        expect(rowNames()).toEqual(['Zoo', 'Bills', 'ApartmentArchived']);
    });

    it('archives an active label without asking', async () => {
        render(<LabelsPage />);

        await chooseRowAction('Bills', 'Archive');

        expect(patch).toHaveBeenCalledWith(
            '/settings/labels/bills/archived',
            { archived: true },
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('unarchives an archived label', async () => {
        render(<LabelsPage />);

        await chooseRowAction('Apartment', 'Unarchive');

        expect(patch).toHaveBeenCalledWith(
            '/settings/labels/apart/archived',
            { archived: false },
            expect.anything(),
        );
    });
});
