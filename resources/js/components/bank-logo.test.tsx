import { BankLogo } from '@/components/bank-logo';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

describe('BankLogo', () => {
    it('falls back when the logo URL fails to load', () => {
        render(
            <BankLogo
                src="https://example.test/gone.png"
                name="ING"
                fallback="letter"
            />,
        );

        const image = screen.getByRole('img', { name: 'ING' });
        fireEvent.error(image);

        expect(screen.queryByRole('img')).toBeNull();
        expect(screen.getByText('I')).toBeInTheDocument();
    });

    it('retries with a new URL after one failed', () => {
        const { rerender } = render(
            <BankLogo
                src="https://example.test/gone.png"
                name="ING"
                fallback="letter"
            />,
        );

        fireEvent.error(screen.getByRole('img', { name: 'ING' }));

        rerender(
            <BankLogo
                src="/images/banks/logos/ing.png"
                name="ING"
                fallback="letter"
            />,
        );

        expect(screen.getByRole('img', { name: 'ING' })).toHaveAttribute(
            'src',
            '/images/banks/logos/ing.png',
        );
    });
});
