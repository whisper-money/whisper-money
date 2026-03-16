import { useCallback, useEffect, useRef } from 'react';
import {
    WebHaptics,
    type HapticInput,
    type TriggerOptions,
    type WebHapticsOptions,
} from 'web-haptics';

interface UseWebHapticsResult {
    trigger: (
        input?: HapticInput,
        options?: TriggerOptions,
    ) => Promise<void> | undefined;
    cancel: () => void;
    isSupported: boolean;
}

export function useWebHaptics(
    options?: WebHapticsOptions,
): UseWebHapticsResult {
    const hapticsRef = useRef<WebHaptics | null>(null);
    const initialOptionsRef = useRef(options);

    useEffect(() => {
        hapticsRef.current = new WebHaptics(initialOptionsRef.current);

        return () => {
            hapticsRef.current?.destroy();
            hapticsRef.current = null;
        };
    }, []);

    useEffect(() => {
        hapticsRef.current?.setDebug(options?.debug ?? false);
    }, [options?.debug]);

    useEffect(() => {
        hapticsRef.current?.setShowSwitch(options?.showSwitch ?? false);
    }, [options?.showSwitch]);

    const trigger = useCallback(
        (input?: HapticInput, triggerOptions?: TriggerOptions) =>
            hapticsRef.current?.trigger(input, triggerOptions),
        [],
    );

    const cancel = useCallback(() => {
        hapticsRef.current?.cancel();
    }, []);

    return {
        trigger,
        cancel,
        isSupported: WebHaptics.isSupported,
    };
}
