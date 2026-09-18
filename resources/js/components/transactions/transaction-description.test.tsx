import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import { render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it } from 'vitest';
import { TransactionDescription } from './transaction-description';

function renderWithPrivacy(ui: React.ReactElement) {
    return render(<PrivacyModeProvider>{ui}</PrivacyModeProvider>);
}

describe('TransactionDescription', () => {
    it('renders the description it is given', () => {
        renderWithPrivacy(
            <TransactionDescription text="Coffee at Starbucks" />,
        );

        expect(screen.getByText('Coffee at Starbucks')).toBeInTheDocument();
    });
});
