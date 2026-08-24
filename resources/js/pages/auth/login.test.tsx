import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Login from './login';

globalThis.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
};

const capturedFormProps: Record<string, unknown> = {};

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
        ...props
    }: {
        children: (renderProps: {
            processing: boolean;
            errors: Record<string, string>;
        }) => ReactNode;
    } & Record<string, unknown>) => {
        Object.assign(capturedFormProps, props);

        return <form>{children({ processing: false, errors: {} })}</form>;
    },
    Head: () => null,
    Link: ({ children }: { children: ReactNode }) => <a>{children}</a>,
    usePage: () => ({ props: { demoCredentials: undefined } }),
}));

vi.mock('@/layouts/auth-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/lib/key-storage', () => ({
    clearKey: vi.fn(),
}));

vi.mock('@/utils/i18n', () => ({
    __: (key: string) => key,
}));

vi.mock('@/routes', () => ({
    register: () => ({ url: '/register' }),
}));

vi.mock('@/routes/login', () => ({
    store: { form: () => ({ action: '/login', method: 'post' }) },
}));

vi.mock('@/routes/password', () => ({
    request: () => ({ url: '/forgot-password' }),
}));

describe('Login', () => {
    it('does not reset the form on success', () => {
        // The success redirect unmounts the form; resetting it afterwards makes
        // Inertia construct a FormData from a stale ref and crash (PHP-LARAVEL-2Y).
        render(<Login canResetPassword canRegister />);

        expect(screen.getByRole('button', { name: /log in/i })).toBeTruthy();
        expect(capturedFormProps.resetOnSuccess).toBeUndefined();
    });
});
