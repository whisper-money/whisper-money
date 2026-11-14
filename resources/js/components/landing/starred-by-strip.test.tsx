import { setTranslations } from '@/utils/i18n';
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { STARRED_BY_COMPANIES } from './starred-by-companies';
import { StarredByStrip } from './starred-by-strip';

function copies(container: HTMLElement): HTMLElement[] {
    return Array.from(container.querySelectorAll('[data-marquee-copy]'));
}

describe('StarredByStrip', () => {
    afterEach(() => {
        cleanup();
        setTranslations({});
    });

    it('labels the band and lists every company once for assistive technology', () => {
        render(<StarredByStrip />);

        const band = screen.getByRole('region', {
            name: 'Starred on GitHub by people at',
        });
        const [visible] = copies(band);

        for (const company of STARRED_BY_COMPANIES) {
            expect(within(visible).getByText(company.name)).toBeInTheDocument();
        }
    });

    it('renders the companies twice, hiding the duplicate copy', () => {
        const { container } = render(<StarredByStrip />);

        const [visible, duplicate] = copies(container);

        expect(copies(container)).toHaveLength(2);
        expect(visible).not.toHaveAttribute('aria-hidden');
        expect(duplicate).toHaveAttribute('aria-hidden', 'true');
        expect(duplicate.textContent).toBe(visible.textContent);
    });

    it('draws an icon only for companies that have one', () => {
        const { container } = render(
            <StarredByStrip
                companies={[
                    { name: 'Acme', iconPath: 'M0 0h24v24H0z' },
                    { name: 'Globex' },
                ]}
            />,
        );

        const [visible] = copies(container);
        const icons = visible.querySelectorAll('svg');

        expect(icons).toHaveLength(1);
        expect(icons[0]).toHaveAttribute('aria-hidden', 'true');
        expect(icons[0].querySelector('path')).toHaveAttribute(
            'd',
            'M0 0h24v24H0z',
        );
        expect(
            within(visible).getByText('Globex').previousElementSibling,
        ).toBeNull();
    });

    it('stops the marquee under reduced motion', () => {
        const { container } = render(<StarredByStrip />);

        for (const copy of copies(container)) {
            expect(copy).toHaveClass('animate-marquee');
            expect(copy.className).toContain('motion-reduce:[animation:none]');
        }
    });

    it('translates the label but not the company names', () => {
        setTranslations({
            'Starred on GitHub by people at':
                'Destacado en GitHub por personas de',
        });

        render(<StarredByStrip companies={[{ name: 'Microsoft' }]} />);

        expect(
            screen.getByRole('region', {
                name: 'Destacado en GitHub por personas de',
            }),
        ).toBeInTheDocument();
        expect(screen.getAllByText('Microsoft')).toHaveLength(2);
    });
});
