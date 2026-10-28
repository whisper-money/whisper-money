import { type DocumentationNavItem } from '@/types/documentation';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import DocNav from './doc-nav';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

function page(title: string): DocumentationNavItem {
    return {
        type: 'page',
        key: `page-${title}`,
        slug: title,
        title,
        url: `/documentation/${title}`,
        active: false,
        expanded: false,
        children: [],
    };
}

function section(
    title: string,
    children: DocumentationNavItem[],
): DocumentationNavItem {
    return {
        type: 'section',
        key: `section-${title}`,
        title,
        active: false,
        expanded: false,
        children,
    };
}

describe('DocNav', () => {
    it('closes a section before the root page that follows it', () => {
        render(
            <DocNav
                items={[
                    page('getting-started'),
                    section('Progress', [
                        page('achievements'),
                        page('monthly-summary'),
                    ]),
                    page('ai-connector'),
                    page('api'),
                ]}
            />,
        );

        const separators = screen.getAllByRole('separator');
        const aiConnector = screen.getByRole('link', { name: 'ai-connector' });

        expect(separators).toHaveLength(1);
        expect(separators[0].nextElementSibling?.contains(aiConnector)).toBe(
            true,
        );
        // The section is a Radix Collapsible: everything inside it carries data-state.
        expect(
            screen
                .getByRole('link', { name: 'achievements' })
                .closest('[data-state]'),
        ).not.toBeNull();
        expect(aiConnector.closest('[data-state]')).toBeNull();
    });

    it('adds no break between sections, nor before a page that leads the list', () => {
        render(
            <DocNav
                items={[
                    page('getting-started'),
                    section('Your data', [page('accounts')]),
                    section('Progress', [page('achievements')]),
                ]}
            />,
        );

        expect(screen.queryAllByRole('separator')).toHaveLength(0);
    });
});
