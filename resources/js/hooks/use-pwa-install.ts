import { detectMobilePlatform, type MobilePlatform } from '@/lib/pwa';
import { useCallback, useEffect, useRef, useState } from 'react';

interface BeforeInstallPromptEvent extends Event {
    prompt(): Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

export function usePwaInstall() {
    const [platform] = useState<MobilePlatform>(() => detectMobilePlatform());
    const deferredPromptRef = useRef<BeforeInstallPromptEvent | null>(null);
    const [canInstall, setCanInstall] = useState(false);

    useEffect(() => {
        if (platform !== 'android') {
            return;
        }

        const handler = (e: Event) => {
            e.preventDefault();
            deferredPromptRef.current = e as BeforeInstallPromptEvent;
            setCanInstall(true);
        };

        window.addEventListener('beforeinstallprompt', handler);

        return () => window.removeEventListener('beforeinstallprompt', handler);
    }, [platform]);

    const promptInstall = useCallback(async (): Promise<boolean> => {
        const prompt = deferredPromptRef.current;

        if (!prompt) {
            return false;
        }

        await prompt.prompt();
        const { outcome } = await prompt.userChoice;
        deferredPromptRef.current = null;
        setCanInstall(false);

        return outcome === 'accepted';
    }, []);

    return {
        platform,
        isMobile: platform !== null,
        canInstall,
        promptInstall,
    };
}
