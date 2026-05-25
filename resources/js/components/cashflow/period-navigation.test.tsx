import { setTranslations } from '@/utils/i18n';
import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PeriodNavigation } from './period-navigation';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'es' } }),
}));

describe('PeriodNavigation', () => {
    afterEach(() => {
        setTranslations({});
    });

    it('translates period type labels at render time', () => {
        setTranslations({
            Month: 'Mes',
            Quarter: 'Trimestre',
            Year: 'Año',
        });

        render(
            <PeriodNavigation
                currentDate={new Date(2026, 0, 1)}
                periodType="month"
                onDateChange={() => undefined}
                onPeriodTypeChange={() => undefined}
            />,
        );

        expect(screen.getByRole('button', { name: 'Mes' })).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Trimestre' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Año' })).toBeInTheDocument();
    });
});
