import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { decrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import { useEffect, useMemo, useRef, useState } from 'react';

type Length = number | { min: number; max: number } | null;

interface EncryptedTextProps {
    encryptedText: string;
    iv: string;
    className?: string;
    length: Length;
}

type DisplayState = 'encrypted' | 'decrypted' | 'loading';

const ENCRYPTED_CHARSET = '0123456789$%&#@!ABCDEFGHIJKLMNOPQRSTUVWXYZ';

function resolveTargetLength(length: Length, fallback: number): number {
    if (typeof length === 'number') {
        return Math.max(1, length);
    }

    if (length && typeof length === 'object') {
        const min = Math.max(1, length.min);
        const max = Math.max(min, length.max);
        const clampedFallback = Math.min(Math.max(fallback, min), max);

        return clampedFallback;
    }

    return Math.max(1, fallback);
}

function generateMaskedText(targetLength: number): string {
    if (targetLength <= 0) {
        return '';
    }

    let result = '';
    for (let index = 0; index < targetLength; index += 1) {
        const randomIndex = Math.floor(
            Math.random() * ENCRYPTED_CHARSET.length,
        );
        result += ENCRYPTED_CHARSET.charAt(randomIndex);
    }

    return result;
}

function getInitialDisplayState(isKeySet: boolean): DisplayState {
    if (!isKeySet) {
        return 'encrypted';
    }
    if (typeof window === 'undefined') {
        return 'encrypted';
    }
    const keyString = getStoredKey();
    return keyString ? 'loading' : 'encrypted';
}

export function EncryptedText(props: EncryptedTextProps) {
    const { encryptedText, iv, className = '', length = null } = props;
    const { isKeySet } = useEncryptionKey();
    const targetLength = useMemo(
        () => resolveTargetLength(length, encryptedText.length),
        [length, encryptedText.length],
    );
    const maskedValue = useMemo(
        () => generateMaskedText(targetLength),
        [targetLength],
    );
    const [cachedDecryption, setCachedDecryption] = useState<{
        encryptedText: string;
        iv: string;
        value: string;
    } | null>(null);
    const [displayState, setDisplayState] = useState<DisplayState>(() =>
        getInitialDisplayState(isKeySet),
    );
    const prevIsKeySetRef = useRef(isKeySet);

    useEffect(() => {
        const wasKeySet = prevIsKeySetRef.current;
        prevIsKeySetRef.current = isKeySet;

        if (!isKeySet) {
            if (wasKeySet) {
                setCachedDecryption(null);
                setDisplayState('encrypted');
            }
            return;
        }

        const keyString = getStoredKey();
        if (!keyString) {
            if (wasKeySet !== isKeySet) {
                setCachedDecryption(null);
                setDisplayState('encrypted');
            }
            return;
        }

        if (!wasKeySet && isKeySet) {
            setDisplayState('loading');
        }

        let cancelled = false;

        importKey(keyString)
            .then((key) => decrypt(encryptedText, key, iv))
            .then((value) => {
                if (cancelled) {
                    return;
                }
                setCachedDecryption({ encryptedText, iv, value });
                setDisplayState('decrypted');
            })
            .catch(() => {
                if (cancelled) {
                    return;
                }
                setCachedDecryption(null);
                setDisplayState('encrypted');
            });

        return () => {
            cancelled = true;
        };
    }, [encryptedText, iv, isKeySet]);

    if (displayState === 'loading') {
        const widthInCharacters = Math.max(targetLength, 3) / 2;
        const loadingClassName = [
            'inline-block animate-pulse rounded bg-muted/60',
            className,
        ]
            .filter(Boolean)
            .join(' ');

        return (
            <span
                className={loadingClassName}
                style={{
                    width: `${widthInCharacters}ch`,
                    height: '1em',
                }}
            />
        );
    }

    if (displayState === 'decrypted' && cachedDecryption) {
        return <span className={className}>{cachedDecryption.value}</span>;
    }

    return <span className={className}>{maskedValue}</span>;
}
