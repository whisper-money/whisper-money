import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AiSuggestionCard, type AiSuggestion } from './ai-suggestion-card';

const post = vi.fn();

vi.mock('axios', () => ({
    default: {
        post: (...args: unknown[]) => post(...args),
        isAxiosError: () => true,
    },
}));

const suggestion: AiSuggestion = {
    id: 'suggestion-1',
    confidence: 0.9,
    group_size: 42,
    sample_descriptions: [],
    proposed_category: { id: 'category-1', name: 'Groceries' },
    new_category_name: null,
    new_category_direction: null,
    values: [
        {
            id: 'value-1',
            match_field: 'description',
            match_operator: 'contains',
            match_token: 'MERCADONA',
        },
    ],
};

describe('AiSuggestionCard when the match preview fails', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        post.mockReset();
        post.mockRejectedValue(new Error('Network Error'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    /**
     * Renders the card, then re-renders with an edited token so the debounced
     * preview fires — an untouched card deliberately makes no request.
     */
    async function renderWithEditedToken() {
        const draft = {
            include: true,
            categoryId: 'category-1',
            values: [
                {
                    field: 'description',
                    operator: 'contains',
                    token: 'MERCADONA',
                },
            ],
        };

        const view = render(
            <AiSuggestionCard
                suggestion={suggestion}
                draft={draft}
                categories={[]}
                onChange={vi.fn()}
            />,
        );

        view.rerender(
            <AiSuggestionCard
                suggestion={suggestion}
                draft={{
                    ...draft,
                    values: [{ ...draft.values[0], token: 'LIDL' }],
                }}
                categories={[]}
                onChange={vi.fn()}
            />,
        );

        await act(async () => {
            await vi.advanceTimersByTimeAsync(500);
        });

        return view;
    }

    it('stops showing a count that belongs to the previous token', async () => {
        await renderWithEditedToken();

        expect(post).toHaveBeenCalled();
        expect(screen.queryByText('42 matches')).not.toBeInTheDocument();
        expect(screen.getByText('? matches')).toBeInTheDocument();
    });
});
