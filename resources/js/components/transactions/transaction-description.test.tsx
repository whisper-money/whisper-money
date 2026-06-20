import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import { render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it } from 'vitest';
import {
    ENCRYPTED_PLACEHOLDER,
    TransactionDescription,
} from './transaction-description';

function renderWithPrivacy(ui: React.ReactElement) {
    return render(<PrivacyModeProvider>{ui}</PrivacyModeProvider>);
}

describe('TransactionDescription', () => {
    it('renders the plaintext description when not encrypted', () => {
        renderWithPrivacy(
            <TransactionDescription text="Coffee at Starbucks" />,
        );

        expect(screen.getByText('Coffee at Starbucks')).toBeInTheDocument();
    });

    it('masks a still-encrypted value instead of leaking raw ciphertext', () => {
        const ciphertext = 'U2FsdGVkX1+abc123def456==';

        renderWithPrivacy(
            <TransactionDescription text={ciphertext} encrypted />,
        );

        expect(screen.queryByText(ciphertext)).not.toBeInTheDocument();
        expect(screen.getByText(ENCRYPTED_PLACEHOLDER)).toBeInTheDocument();
    });
});
