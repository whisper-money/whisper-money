import { renderHook } from '@testing-library/react';
import { renderToString } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { usePlatform } from './use-platform';

function Platform() {
    return <>{usePlatform() ?? 'unknown'}</>;
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('usePlatform', () => {
    it('reads the platform in the browser', () => {
        vi.spyOn(Navigator.prototype, 'platform', 'get').mockReturnValue(
            'MacIntel',
        );

        expect(renderHook(() => usePlatform()).result.current).toBe('mac');
    });

    it('does not guess on the server', () => {
        vi.spyOn(Navigator.prototype, 'platform', 'get').mockReturnValue(
            'MacIntel',
        );

        expect(renderToString(<Platform />)).toBe('unknown');
    });
});
