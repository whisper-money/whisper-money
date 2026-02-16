import { clearKey, getStoredKey } from '@/lib/key-storage';
import axios from 'axios';
import {
    createContext,
    type ReactNode,
    useCallback,
    useContext,
    useEffect,
    useState,
} from 'react';

interface EncryptedMessageData {
    encrypted_content: string;
    iv: string;
    salt: string;
}

interface EncryptionKeyContextType {
    isKeySet: boolean;
    refreshKeyState: () => void;
    encryptedMessageData: EncryptedMessageData | null;
    fetchEncryptedMessage: () => Promise<void>;
    clearEncryptionKey: () => void;
}

const EncryptionKeyContext = createContext<
    EncryptionKeyContextType | undefined
>(undefined);

interface EncryptionKeyProviderProps {
    hasEncryptionSetup: boolean;
    children: ReactNode;
}

export function EncryptionKeyProvider({
    hasEncryptionSetup,
    children,
}: EncryptionKeyProviderProps) {
    const [isKeySet, setIsKeySet] = useState(!hasEncryptionSetup);
    const [encryptedMessageData, setEncryptedMessageData] =
        useState<EncryptedMessageData | null>(null);

    const refreshKeyState = useCallback(() => {
        if (!hasEncryptionSetup) {
            setIsKeySet(true);
            return;
        }
        const key = getStoredKey();
        setIsKeySet(!!key);
    }, [hasEncryptionSetup]);

    async function fetchEncryptedMessage() {
        if (!hasEncryptionSetup) {
            return;
        }

        try {
            const response = await axios.get<EncryptedMessageData>(
                '/api/encryption/message',
            );
            setEncryptedMessageData(response.data);
        } catch (err) {
            console.error('Failed to fetch encrypted message:', err);
        }
    }

    function clearEncryptionKey() {
        clearKey();
        refreshKeyState();
    }

    useEffect(() => {
        refreshKeyState();

        if (!hasEncryptionSetup) {
            return;
        }

        const interval = setInterval(() => {
            const key = getStoredKey();
            setIsKeySet(!!key);
        }, 1000);

        return () => clearInterval(interval);
    }, [hasEncryptionSetup, refreshKeyState]);

    return (
        <EncryptionKeyContext.Provider
            value={{
                isKeySet,
                refreshKeyState,
                encryptedMessageData,
                fetchEncryptedMessage,
                clearEncryptionKey,
            }}
        >
            {children}
        </EncryptionKeyContext.Provider>
    );
}

export function useEncryptionKey() {
    const context = useContext(EncryptionKeyContext);
    if (context === undefined) {
        throw new Error(
            'useEncryptionKey must be used within an EncryptionKeyProvider',
        );
    }
    return context;
}
