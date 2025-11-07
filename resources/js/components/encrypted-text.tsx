import { useState, useEffect } from 'react';
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

export function EncryptedText({
    encryptedText,
    iv,
    interval = 30,
    className = '',
    fallback = '[Encrypted]',
}: EncryptedTextProps) {
    const { isKeySet } = useEncryptionKey();
    const [decryptedText, setDecryptedText] = useState<string | null>(null);
    const [outputText, setOutputText] = useState('');
    const [isMounted, setIsMounted] = useState(false);
    const [targetText, setTargetText] = useState('');

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
            setTargetText(generateRandomChars(fallback.length));
        }
    }, [decryptedText, isKeySet, fallback.length]);

    useEffect(() => {
        let timer: NodeJS.Timeout;

        if (outputText !== targetText) {
            let currentIndex = 0;

            timer = setInterval(() => {
                if (currentIndex < targetText.length) {
                    setOutputText(targetText.slice(0, currentIndex + 1));
                    currentIndex++;
                } else {
                    clearInterval(timer);
                }
            }, interval);
        }

        return () => clearInterval(timer);
    }, [targetText, interval]);

    function generateRandomChars(length: number): string {
        return Array.from({ length }, () =>
            chars[Math.floor(Math.random() * chars.length)]
        ).join('');
    }

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
        <span className={className}>
            {outputText}
            {remainder}
        </span>
    );
}

