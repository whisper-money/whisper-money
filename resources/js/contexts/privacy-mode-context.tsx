import {
    createContext,
    type ReactNode,
    useContext,
    useEffect,
    useState,
} from 'react';

const PRIVACY_MODE_STORAGE_KEY = 'privacy-mode-enabled';

interface PrivacyModeContextType {
    isPrivacyModeEnabled: boolean;
    togglePrivacyMode: () => void;
    setPrivacyMode: (enabled: boolean) => void;
}

const PrivacyModeContext = createContext<PrivacyModeContextType | undefined>(
    undefined,
);

function getStoredPrivacyMode(): boolean {
    try {
        const stored = localStorage.getItem(PRIVACY_MODE_STORAGE_KEY);
        return stored ? JSON.parse(stored) : false;
    } catch {
        return false;
    }
}

function setStoredPrivacyMode(enabled: boolean): void {
    try {
        localStorage.setItem(PRIVACY_MODE_STORAGE_KEY, JSON.stringify(enabled));
    } catch (error) {
        console.error('Failed to store privacy mode setting:', error);
    }
}

export function PrivacyModeProvider({ children }: { children: ReactNode }) {
    const [isPrivacyModeEnabled, setIsPrivacyModeEnabled] = useState(() =>
        getStoredPrivacyMode(),
    );

    useEffect(() => {
        setStoredPrivacyMode(isPrivacyModeEnabled);
    }, [isPrivacyModeEnabled]);

    const togglePrivacyMode = () => {
        setIsPrivacyModeEnabled((prev) => !prev);
    };

    const setPrivacyMode = (enabled: boolean) => {
        setIsPrivacyModeEnabled(enabled);
    };

    return (
        <PrivacyModeContext.Provider
            value={{
                isPrivacyModeEnabled,
                togglePrivacyMode,
                setPrivacyMode,
            }}
        >
            {children}
        </PrivacyModeContext.Provider>
    );
}

export function usePrivacyMode() {
    const context = useContext(PrivacyModeContext);
    if (context === undefined) {
        throw new Error(
            'usePrivacyMode must be used within a PrivacyModeProvider',
        );
    }
    return context;
}
