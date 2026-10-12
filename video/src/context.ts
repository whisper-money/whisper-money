import { createContext, useContext } from 'react';
import { type Copy, type Locale } from './copy';
import { type Theme } from './theme';

type VideoContextValue = { theme: Theme; copy: Copy; locale: Locale };

export const VideoContext = createContext<VideoContextValue | null>(null);

export function useVideo(): VideoContextValue {
    const value = useContext(VideoContext);

    if (value === null) {
        throw new Error('useVideo must be used inside <VideoContext.Provider>');
    }

    return value;
}
