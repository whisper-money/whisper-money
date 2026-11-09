import { render, screen } from '@testing-library/react';
import { Link, setPageProps, usePage } from './inertia-mock';

describe('inertia mock', () => {
    it('serves the props a video sets as the page props', () => {
        setPageProps({ locale: 'es-ES' });

        expect(usePage<{ locale: string }>().props.locale).toBe('es-ES');
    });

    it('renders links as plain anchors', () => {
        render(<Link href="/transactions">Transactions</Link>);

        expect(
            screen
                .getByRole('link', { name: 'Transactions' })
                .getAttribute('href'),
        ).toBe('/transactions');
    });
});
