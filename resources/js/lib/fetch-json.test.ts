import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchJson } from './fetch-json';

function respondWith(status: number, body: unknown) {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            ok: status >= 200 && status < 300,
            status,
            json: () => Promise.resolve(body),
        }),
    );
}

describe('fetchJson', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('returns the parsed body of a successful response', async () => {
        respondWith(200, { total_income: 4200 });

        await expect(fetchJson('/api/cashflow/summary')).resolves.toEqual({
            total_income: 4200,
        });
    });

    it('rejects a server error instead of handing back its body', async () => {
        // Laravel answers a JSON request with a JSON body on the way down too, so
        // `{"message": "Server Error"}` parses perfectly. Spread into a chart's
        // state it became a screen full of zeros with nothing logged.
        respondWith(500, { message: 'Server Error' });

        await expect(fetchJson('/api/cashflow/summary')).rejects.toThrow(
            'answered 500',
        );
    });

    it('rejects a throttled response', async () => {
        respondWith(429, { message: 'Too Many Requests' });

        await expect(fetchJson('/api/cashflow/trend')).rejects.toThrow(
            'answered 429',
        );
    });
});
