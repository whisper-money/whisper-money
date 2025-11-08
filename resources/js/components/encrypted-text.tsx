import { useEffect, useMemo, useRef, useState } from 'react';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { getStoredKey } from '@/lib/key-storage';
import { importKey, decrypt } from '@/lib/crypto';

type Length = number | { min: number; max: number } | null;

interface EncryptedTextProps {
    encryptedText: string;
    iv: string;
    className?: string;
    length: Length;
}

function randInt(min: number, max: number) {
    return Math.floor(Math.random() * (max - min + 1)) + min;
}

const LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

export function EncryptedText(props: EncryptedTextProps) {
    const { encryptedText, iv, className = '', length = null } = props;
    const { isKeySet } = useEncryptionKey();
    const maxLength = useMemo<number>(() => {
        if (typeof length === 'number') {
            return length;
        }

        if (length && typeof length === 'object' && 'max' in length) {
            return randInt(length.min, length.max)
        }

        return randInt(10, 20)
    }, [length]);
    const [cachedDecryption, setCachedDecryption] = useState<{ encryptedText: string; iv: string; value: string; } | null>(null);
    const maskedText = useMemo(() => {
        if (isKeySet && cachedDecryption?.value) {
            return cachedDecryption.value;
        }

        return encryptedText.slice(0, maxLength);
    }, [cachedDecryption?.value, encryptedText, isKeySet, maxLength]);
    const [displayValue, setDisplayValue] = useState<string>(maskedText);
    const intervalRef = useRef<number | null>(null);
    const displayValueRef = useRef(displayValue);

    useEffect(() => {
        if (!isKeySet) {
            setCachedDecryption(null);
            return;
        }

        if (
            cachedDecryption &&
            cachedDecryption.encryptedText === encryptedText &&
            cachedDecryption.iv === iv
        ) {
            return;
        }

        const keyString = getStoredKey();
        if (!keyString) {
            setCachedDecryption(null);
            return;
        }

        importKey(keyString)
            .then((key) => decrypt(encryptedText, key, iv))
            .then((value) => {
                setCachedDecryption({ encryptedText, iv, value });
            })
            .catch(() => {
                setCachedDecryption(null);
            });
    }, [encryptedText, iv, isKeySet]);

    useEffect(() => {
        displayValueRef.current = displayValue;
    }, [displayValue]);

    useEffect(() => {
        if (intervalRef.current) {
            window.clearInterval(intervalRef.current);
            intervalRef.current = null;
        }

        if (maskedText === displayValueRef.current) {
            return;
        }

        if (!maskedText.length) {
            displayValueRef.current = '';
            setDisplayValue('');
            return;
        }

        let iteration = 0;
        const target = maskedText;
        const targetLength = target.length;

        intervalRef.current = window.setInterval(() => {
            iteration += 1;

            if (iteration >= targetLength) {
                if (intervalRef.current) {
                    window.clearInterval(intervalRef.current);
                    intervalRef.current = null;
                }

                displayValueRef.current = target;
                setDisplayValue(target);
                return;
            }

            const revealCount = Math.floor(iteration);

            const randomValue = Array.from({ length: targetLength }, (_, index) => {
                if (index < revealCount) {
                    return target[index];
                }

                return LETTERS[Math.floor(Math.random() * LETTERS.length)];
            }).join('');

            displayValueRef.current = randomValue;
            setDisplayValue(randomValue);
        }, 20);

        return () => {
            if (intervalRef.current) {
                window.clearInterval(intervalRef.current);
                intervalRef.current = null;
            }
        };
    }, [maskedText]);

    return <span className={className}>{displayValue}</span>;
}
