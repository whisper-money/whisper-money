import { createContext, useContext } from 'react';
import { type Copy } from './copy';
import { type Theme } from './theme';

export const VideoContext = createContext<{ theme: Theme; copy: Copy } | null>(null);

export function useVideo(): { theme: Theme; copy: Copy } {
    const value = useContext(VideoContext);

    if (value === null) {
        throw new Error('useVideo must be used inside <VideoContext.Provider>');
    }

    return value;
}
