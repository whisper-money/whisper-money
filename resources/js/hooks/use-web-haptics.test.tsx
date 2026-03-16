import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useWebHaptics } from './use-web-haptics';

const {
    cancelMock,
    destroyMock,
    setDebugMock,
    setShowSwitchMock,
    triggerMock,
    webHapticsConstructorMock,
} = vi.hoisted(() => {
    const trigger = vi.fn();
    const cancel = vi.fn();
    const destroy = vi.fn();
    const setDebug = vi.fn();
    const setShowSwitch = vi.fn();
    const constructor = vi.fn(() => ({
        trigger,
        cancel,
        destroy,
        setDebug,
        setShowSwitch,
    }));

    return {
        cancelMock: cancel,
        destroyMock: destroy,
        setDebugMock: setDebug,
        setShowSwitchMock: setShowSwitch,
        triggerMock: trigger,
        webHapticsConstructorMock: constructor,
    };
});

vi.mock('web-haptics', () => ({
    WebHaptics: Object.assign(webHapticsConstructorMock, {
        isSupported: true,
    }),
}));

describe('useWebHaptics', () => {
    beforeEach(() => {
        cancelMock.mockReset();
        destroyMock.mockReset();
        setDebugMock.mockReset();
        setShowSwitchMock.mockReset();
        triggerMock.mockReset();
        webHapticsConstructorMock.mockClear();
    });

    afterEach(() => {
        vi.clearAllMocks();
    });

    it('creates a WebHaptics instance and proxies methods safely', () => {
        triggerMock.mockResolvedValue(undefined);

        const { result, unmount } = renderHook(() =>
            useWebHaptics({ debug: true, showSwitch: true }),
        );

        expect(webHapticsConstructorMock).toHaveBeenCalledWith({
            debug: true,
            showSwitch: true,
        });
        expect(result.current.isSupported).toBe(true);

        result.current.trigger('selection');
        result.current.cancel();

        expect(triggerMock).toHaveBeenCalledWith('selection', undefined);
        expect(cancelMock).toHaveBeenCalledOnce();
        expect(setDebugMock).toHaveBeenCalledWith(true);
        expect(setShowSwitchMock).toHaveBeenCalledWith(true);

        unmount();

        expect(destroyMock).toHaveBeenCalledOnce();
    });
});
