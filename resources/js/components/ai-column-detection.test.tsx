import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AiColumnDetection } from './ai-column-detection';

describe('AiColumnDetection', () => {
    it('renders nothing while AI column mapping is off', () => {
        const { container } = render(
            <AiColumnDetection status="hidden" onDetect={vi.fn()} />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('marks a mapping that is still the suggestion', () => {
        render(<AiColumnDetection status="suggested" onDetect={vi.fn()} />);

        expect(screen.getByText('Suggested by AI')).toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('offers to detect the columns otherwise', () => {
        const onDetect = vi.fn();
        render(<AiColumnDetection status="idle" onDetect={onDetect} />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Detect columns with AI' }),
        );

        expect(onDetect).toHaveBeenCalledOnce();
    });

    it('cannot be pressed again while it is detecting', () => {
        render(<AiColumnDetection status="loading" onDetect={vi.fn()} />);

        expect(
            screen.getByRole('button', { name: /Detect columns with AI/ }),
        ).toBeDisabled();
    });
});
