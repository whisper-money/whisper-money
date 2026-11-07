import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useImportLinkLanding } from './use-import-link-landing';

const { captureEvent, flash } = vi.hoisted(() => ({
    captureEvent: vi.fn(),
    flash: { current: {} as Record<string, unknown> },
}));

vi.mock('@/lib/posthog', () => ({ captureEvent }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { flash: flash.current } }),
}));

describe('useImportLinkLanding', () => {
    beforeEach(() => {
        captureEvent.mockReset();
        flash.current = {};
    });

    it('reports the link opened once, with where it landed and nothing else', () => {
        flash.current = { import_link_account_id: 'account-1' };

        const { result, rerender } = renderHook(() =>
            useImportLinkLanding('onboarding'),
        );
        rerender();

        expect(result.current).toBe('account-1');
        expect(captureEvent).toHaveBeenCalledTimes(1);
        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_import_link_opened',
            { landing: 'onboarding' },
        );
    });

    it('keeps the account after the flash is gone', () => {
        flash.current = { import_link_account_id: 'account-1' };
        const { result, rerender } = renderHook(() =>
            useImportLinkLanding('importer'),
        );

        flash.current = {};
        rerender();

        expect(result.current).toBe('account-1');
    });

    it('reports nothing on an ordinary visit', () => {
        const { result } = renderHook(() => useImportLinkLanding('importer'));

        expect(result.current).toBeNull();
        expect(captureEvent).not.toHaveBeenCalled();
    });
});
