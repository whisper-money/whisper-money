import { setTranslations } from '@/utils/i18n';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import AlertError from './alert-error';

describe('AlertError', () => {
    afterEach(() => {
        cleanup();
        setTranslations({});
    });

    it('translates the default title', () => {
        setTranslations({ 'Something went wrong.': 'Algo salió mal.' });

        render(<AlertError errors={['Boom']} />);

        expect(screen.getByText('Algo salió mal.')).toBeInTheDocument();
        expect(
            screen.queryByText('Something went wrong.'),
        ).not.toBeInTheDocument();
    });

    it('shows the given title instead of the default one', () => {
        render(<AlertError errors={['Boom']} title="Import failed" />);

        expect(screen.getByText('Import failed')).toBeInTheDocument();
        expect(
            screen.queryByText('Something went wrong.'),
        ).not.toBeInTheDocument();
    });

    it('lists each distinct error once', () => {
        render(<AlertError errors={['Boom', 'Boom', 'Bang']} />);

        expect(screen.getAllByRole('listitem')).toHaveLength(2);
    });
});
