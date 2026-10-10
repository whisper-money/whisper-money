import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
    type ReactNode,
} from 'react';

const PRIVACY_MODE_STORAGE_KEY = 'privacy-mode-enabled';

interface PrivacyModeContextType {
    /** Whether amounts must be masked here: false inside a revealed block. */
    isPrivacyModeEnabled: boolean;
    /**
     * The user's privacy mode setting, regardless of any revealed block. Read
     * this, not isPrivacyModeEnabled, for anything that shows or flips the
     * setting itself (the logo, the privacy toggle).
     */
    isGlobalPrivacyModeEnabled: boolean;
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

    const togglePrivacyMode = useCallback(() => {
        setIsPrivacyModeEnabled((prev) => !prev);
    }, []);

    return (
        <PrivacyModeContext.Provider
            value={{
                isPrivacyModeEnabled,
                isGlobalPrivacyModeEnabled: isPrivacyModeEnabled,
                togglePrivacyMode,
                setPrivacyMode: setIsPrivacyModeEnabled,
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

interface PrivacyRevealContextType {
    isRevealed: boolean;
    toggleReveal: () => void;
}

const PrivacyRevealContext = createContext<
    PrivacyRevealContextType | undefined
>(undefined);

/**
 * Lets one block of the page show its amounts while privacy mode stays on.
 * Revealing re-provides the privacy context to the subtree with masking off,
 * so every consumer inside (amounts, chart tooltips) unmasks unchanged. The
 * reveal lives in memory only and is dropped whenever global privacy changes.
 */
export function PrivacyRevealScope({ children }: { children: ReactNode }) {
    const privacyMode = usePrivacyMode();
    const { isGlobalPrivacyModeEnabled, togglePrivacyMode, setPrivacyMode } =
        privacyMode;
    const [isRevealed, setIsRevealed] = useState(false);
    const [revealedUnderGlobal, setRevealedUnderGlobal] = useState(
        isGlobalPrivacyModeEnabled,
    );

    if (revealedUnderGlobal !== isGlobalPrivacyModeEnabled) {
        setRevealedUnderGlobal(isGlobalPrivacyModeEnabled);
        setIsRevealed(false);
    }

    const scopedPrivacyMode = useMemo(
        () => ({
            isPrivacyModeEnabled: isGlobalPrivacyModeEnabled && !isRevealed,
            isGlobalPrivacyModeEnabled,
            togglePrivacyMode,
            setPrivacyMode,
        }),
        [
            isGlobalPrivacyModeEnabled,
            isRevealed,
            togglePrivacyMode,
            setPrivacyMode,
        ],
    );

    const reveal = useMemo(
        () => ({
            isRevealed,
            toggleReveal: () => setIsRevealed((previous) => !previous),
        }),
        [isRevealed],
    );

    return (
        <PrivacyModeContext.Provider value={scopedPrivacyMode}>
            <PrivacyRevealContext.Provider value={reveal}>
                {children}
            </PrivacyRevealContext.Provider>
        </PrivacyModeContext.Provider>
    );
}

/** The reveal state of the enclosing PrivacyRevealScope, or null outside one. */
export function usePrivacyReveal(): PrivacyRevealContextType | null {
    return useContext(PrivacyRevealContext) ?? null;
}
