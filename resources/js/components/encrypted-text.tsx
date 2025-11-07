import { useState, useEffect, useRef } from 'react';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { getStoredKey } from '@/lib/key-storage';
import { importKey, decrypt } from '@/lib/crypto';

interface EncryptedTextProps {
    encryptedText: string;
    iv: string;
    interval?: number;
    className?: string;
    fallback?: string;
}

const chars = "-_~`!@#$%^&*()+=[]{}|;:,.<>?";

function generateRandomChars(length: number): string {
    return Array.from({ length }, () =>
        chars[Math.floor(Math.random() * chars.length)]
    ).join('');
}

export function EncryptedText({
    encryptedText,
    iv,
    interval = 30,
    className = '',
    fallback = '[Encrypted]',
}: EncryptedTextProps) {
    const { isKeySet } = useEncryptionKey();
    const [decryptedText, setDecryptedText] = useState<string | null>(null);
    const [isMounted, setIsMounted] = useState(false);
    const [isAnimating, setIsAnimating] = useState(false);
    const isFirstLoad = useRef(true);
    const fallbackCharsRef = useRef<string>(generateRandomChars(fallback.length));
    const [targetText, setTargetText] = useState(() => fallbackCharsRef.current);
    const [outputText, setOutputText] = useState(() => fallbackCharsRef.current);

    useEffect(() => {
        setIsMounted(true);
    }, []);

    useEffect(() => {
        async function decryptData() {
            const keyString = getStoredKey();
            if (!keyString || !isKeySet) {
                setDecryptedText(null);
                return;
            }

            try {
                const key = await importKey(keyString);
                const text = await decrypt(encryptedText, key, iv);
                setDecryptedText(text);
            } catch (err) {
                console.error('Failed to decrypt text:', err);
                setDecryptedText(null);
            }
        }

        decryptData();
    }, [encryptedText, iv, isKeySet]);

    useEffect(() => {
        if (decryptedText !== null && isKeySet) {
            setTargetText(decryptedText);
        } else {
            setTargetText(fallbackCharsRef.current);
        }
    }, [decryptedText, isKeySet]);

    useEffect(() => {
        let timer: NodeJS.Timeout;

        if (isFirstLoad.current) {
            setOutputText(targetText);
            isFirstLoad.current = false;
            return;
        }

        if (outputText !== targetText) {
            setIsAnimating(true);
            let currentIndex = 0;

            timer = setInterval(() => {
                if (currentIndex < targetText.length) {
                    setOutputText(targetText.slice(0, currentIndex + 1));
                    currentIndex++;
                } else {
                    clearInterval(timer);
                    setIsAnimating(false);
                }
            }, interval);
        }

        return () => {
            clearInterval(timer);
            setIsAnimating(false);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [targetText, interval]);

    const remainder =
        outputText.length < targetText.length
            ? targetText
                  .slice(outputText.length)
                  .split('')
                  .map(() => chars[Math.floor(Math.random() * chars.length)])
                  .join('')
            : '';

    if (!isMounted) {
        return <span className={className}> </span>;
    }

    return (
        <span className={`${className} ${isAnimating ? 'animate-pulse' : ''}`}>
            {outputText}
            {remainder}
        </span>
    );
}

