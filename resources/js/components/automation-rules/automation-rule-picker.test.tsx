import type { AutomationRule } from '@/types/automation-rule';
import type { Category } from '@/types/category';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { AutomationRulePicker } from './automation-rule-picker';

// jsdom ships no `matchMedia`; each test picks the viewport it renders on.
const viewport = vi.hoisted(() => ({ isMobile: false }));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => viewport.isMobile }));

function makeRule(id: string, title: string, categoryName: string) {
    return {
        id,
        title,
        category: { name: categoryName } as Category,
    } as AutomationRule;
}

const rules = [
    makeRule('rule-1', 'AA · Dia', 'AA · Supermercado'),
    makeRule('rule-2', 'GI · Fitness Park', 'GI · Gimnasio'),
    makeRule('rule-3', 'Café', 'Bares y cafeterías'),
];

function renderPicker(onSelect = vi.fn(), onBack = vi.fn()) {
    render(
        <AutomationRulePicker
            rules={rules}
            onBack={onBack}
            onSelect={onSelect}
        />,
    );

    return { onSelect, onBack };
}

function search(query: string) {
    fireEvent.change(screen.getByRole('searchbox'), {
        target: { value: query },
    });
}

describe('AutomationRulePicker', () => {
    beforeEach(() => {
        viewport.isMobile = false;
    });

    it('focuses the search on desktop', () => {
        renderPicker();

        expect(screen.getByRole('searchbox')).toHaveFocus();
    });

    it('leaves the keyboard closed on a phone', () => {
        viewport.isMobile = true;
        renderPicker();

        expect(screen.getByRole('searchbox')).not.toHaveFocus();
    });

    it('lists every rule before a query is typed', () => {
        renderPicker();

        expect(screen.getByText('AA · Dia')).toBeInTheDocument();
        expect(screen.getByText('GI · Fitness Park')).toBeInTheDocument();
        expect(screen.getByText('Café')).toBeInTheDocument();
    });

    it('narrows the list by title and by category', () => {
        renderPicker();

        search('fitness');
        expect(screen.getByText('GI · Fitness Park')).toBeInTheDocument();
        expect(screen.queryByText('AA · Dia')).not.toBeInTheDocument();

        search('supermercado');
        expect(screen.getByText('AA · Dia')).toBeInTheDocument();
        expect(screen.queryByText('GI · Fitness Park')).not.toBeInTheDocument();
    });

    it('ignores accents in the query', () => {
        renderPicker();

        search('cafeterias');
        expect(screen.getByText('Café')).toBeInTheDocument();
        expect(screen.queryByText('AA · Dia')).not.toBeInTheDocument();
    });

    it('shows an empty state when nothing matches', () => {
        renderPicker();

        search('netflix');
        expect(screen.getByText('No rules found.')).toBeInTheDocument();
        for (const rule of rules) {
            expect(screen.queryByText(rule.title)).not.toBeInTheDocument();
        }
    });

    it('picks the clicked rule', () => {
        const { onSelect } = renderPicker();

        search('fitness');
        fireEvent.click(screen.getByText('GI · Fitness Park'));
        expect(onSelect).toHaveBeenCalledWith(rules[1]);
    });

    it('goes back', () => {
        const { onBack } = renderPicker();

        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(onBack).toHaveBeenCalled();
    });
});
