import { clearKey, getStoredKey } from '@/lib/key-storage';
import axios from 'axios';
import {
    createContext,
    type ReactNode,
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

export function EncryptionKeyProvider({ children }: { children: ReactNode }) {
    const [isKeySet, setIsKeySet] = useState(false);
    const [encryptedMessageData, setEncryptedMessageData] =
        useState<EncryptedMessageData | null>(null);

    function refreshKeyState() {
        const key = getStoredKey();
        setIsKeySet(!!key);
    }

    async function fetchEncryptedMessage() {
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

        const interval = setInterval(() => {
            const key = getStoredKey();
            setIsKeySet(!!key);
        }, 1000);

        return () => clearInterval(interval);
    }, []);

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
