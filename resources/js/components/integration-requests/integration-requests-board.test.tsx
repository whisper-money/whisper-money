import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    IntegrationRequestsBoard,
    type IntegrationRequestItem,
} from './integration-requests-board';

const item: IntegrationRequestItem = {
    id: '0194d20b-2b25-7000-8000-000000000001',
    name: 'Degiro',
    url: 'https://degiro.es',
    status: 'not_doable',
    comment:
        '> No ofrece una [API pública](https://degiro.es/api) para conexiones.',
    votes_count: 3,
    has_voted: false,
    can_unvote: false,
    created_at: '2026-01-01T00:00:00.000000Z',
};

describe('IntegrationRequestsBoard', () => {
    it('renders the comment markdown as formatted HTML', () => {
        render(
            <IntegrationRequestsBoard
                initialRequests={[item]}
                initialActionsRemaining={5}
            />,
        );

        const link = screen.getByRole('link', { name: 'API pública' });
        expect(link.getAttribute('href')).toBe('https://degiro.es/api');
        expect(link.getAttribute('target')).toBe('_blank');
        expect(document.querySelector('blockquote')).not.toBeNull();
    });
});
