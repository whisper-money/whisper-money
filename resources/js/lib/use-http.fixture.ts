import { useState } from 'react';

/**
 * A stand-in for Inertia's `useHttp` for component tests: the form data lives
 * in real React state, so a component re-renders as the user fills it in, and
 * `post` hands the URL and the data to whatever the test wants to assert on.
 */
export function fakeUseHttp(
    post: (url: string, data: unknown) => Promise<unknown>,
) {
    return function useHttp<T extends Record<string, unknown>>(initial: T) {
        const [data, setAll] = useState(initial);

        return {
            data,
            processing: false,
            setData: (key: keyof T, value: unknown) =>
                setAll((current) => ({ ...current, [key]: value })),
            post: (url: string) => post(url, data),
        };
    };
}
