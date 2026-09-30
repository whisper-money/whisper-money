import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import { setTranslations } from '@/utils/i18n';
import { render, screen } from '@testing-library/react';
import type React from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import { TransactionDescription } from './transaction-description';

function renderWithPrivacy(ui: React.ReactElement) {
    return render(<PrivacyModeProvider>{ui}</PrivacyModeProvider>);
}

describe('TransactionDescription', () => {
    afterEach(() => {
        setTranslations({});
    });

    it('renders the description it is given', () => {
        renderWithPrivacy(
            <TransactionDescription text="Coffee at Starbucks" />,
        );

        expect(screen.getByText('Coffee at Starbucks')).toBeInTheDocument();
    });

    it.each<{
        locale: string;
        translations: Record<string, string>;
        fake: string;
    }>([
        { locale: 'English', translations: {}, fake: 'Gym Membership' },
        {
            locale: 'Spanish',
            translations: { 'Gym Membership': 'Cuota del gimnasio' },
            fake: 'Cuota del gimnasio',
        },
    ])(
        'hides the description behind the same fake concept in $locale when privacy mode is on',
        ({ translations, fake }) => {
            localStorage.setItem('privacy-mode-enabled', 'true');
            setTranslations(translations);

            renderWithPrivacy(
                <TransactionDescription text="Coffee at Starbucks" />,
            );

            expect(screen.getByText(fake)).toBeInTheDocument();
            expect(
                screen.queryByText('Coffee at Starbucks'),
            ).not.toBeInTheDocument();
        },
    );
});
