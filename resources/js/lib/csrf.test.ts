import { afterEach, describe, expect, it } from 'vitest';
import { getCsrfToken } from './csrf';

describe('getCsrfToken', () => {
    afterEach(() => {
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
        document.cookie = 'other=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    });

    it('returns the decoded XSRF-TOKEN cookie value', () => {
        document.cookie = 'XSRF-TOKEN=abc%3D%3D123';

        expect(getCsrfToken()).toBe('abc==123');
    });

    it('finds the token among other cookies', () => {
        document.cookie = 'other=value';
        document.cookie = 'XSRF-TOKEN=token';

        expect(getCsrfToken()).toBe('token');
    });

    it('returns an empty string when the cookie is missing', () => {
        expect(getCsrfToken()).toBe('');
    });
});
