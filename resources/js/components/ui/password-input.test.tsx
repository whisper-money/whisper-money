import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { PasswordInput } from './password-input';

describe('PasswordInput', () => {
    it('renders masked by default with a show toggle', () => {
        render(<PasswordInput placeholder="Password" />);

        expect(screen.getByPlaceholderText('Password')).toHaveAttribute(
            'type',
            'password',
        );

        const toggle = screen.getByRole('button');
        expect(toggle).toHaveAttribute('aria-pressed', 'false');
    });

    it('reveals and re-hides the value when the toggle is clicked', () => {
        render(<PasswordInput placeholder="Password" />);

        const input = screen.getByPlaceholderText('Password');
        const toggle = screen.getByRole('button');

        fireEvent.click(toggle);
        expect(input).toHaveAttribute('type', 'text');
        expect(toggle).toHaveAttribute('aria-pressed', 'true');

        fireEvent.click(toggle);
        expect(input).toHaveAttribute('type', 'password');
        expect(toggle).toHaveAttribute('aria-pressed', 'false');
    });

    it('forwards the ref to the underlying input', () => {
        let node: HTMLInputElement | null = null;
        render(
            <PasswordInput
                ref={(element) => {
                    node = element;
                }}
                placeholder="Password"
            />,
        );

        expect(node).toBeInstanceOf(HTMLInputElement);
    });

    it('disables the toggle when the input is disabled', () => {
        render(<PasswordInput placeholder="Password" disabled />);

        expect(screen.getByRole('button')).toBeDisabled();
    });
});
