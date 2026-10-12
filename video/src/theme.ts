export type ThemeName = 'light' | 'dark';

export type Theme = {
    name: ThemeName;
    background: string;
    surface: string;
    surfaceMuted: string;
    border: string;
    text: string;
    muted: string;
    faint: string;
    buttonFrom: string;
    buttonTo: string;
    buttonText: string;
    positive: string;
    positiveSoft: string;
    negative: string;
    negativeSoft: string;
    warning: string;
    glow: string;
    shadow: string;
    chart: [string, string, string, string];
    sheetHeader: string;
    sheetGrid: string;
};

/**
 * Mirrors the landing page palette (welcome.tsx) so the video blends into
 * the section it is embedded in.
 */
export const themes: Record<ThemeName, Theme> = {
    dark: {
        name: 'dark',
        background: '#0a0a0a',
        surface: '#161615',
        surfaceMuted: '#1f1f1d',
        border: '#2c2c2a',
        text: '#EDEDEC',
        muted: '#A1A09A',
        faint: '#62605b',
        buttonFrom: '#e4e4e7',
        buttonTo: '#d4d4d8',
        buttonText: '#1C1C1A',
        positive: '#4ade80',
        positiveSoft: 'rgba(34, 197, 94, 0.16)',
        negative: '#f87171',
        negativeSoft: 'rgba(239, 68, 68, 0.16)',
        warning: '#fbbf24',
        glow: 'rgba(237, 237, 236, 0.10)',
        shadow: '0 40px 120px rgba(0, 0, 0, 0.6)',
        chart: ['#a1a1aa', '#71717a', '#52525b', '#3f3f46'],
        sheetHeader: '#1f1f1d',
        sheetGrid: '#2c2c2a',
    },
    light: {
        name: 'light',
        background: '#FDFDFC',
        surface: '#FFFFFF',
        surfaceMuted: '#f5f5f3',
        border: '#e3e3e0',
        text: '#1b1b18',
        muted: '#706f6c',
        faint: '#b5b4ae',
        buttonFrom: '#3f3f46',
        buttonTo: '#18181b',
        buttonText: '#ffffff',
        positive: '#16a34a',
        positiveSoft: 'rgba(22, 163, 74, 0.12)',
        negative: '#dc2626',
        negativeSoft: 'rgba(220, 38, 38, 0.10)',
        warning: '#d97706',
        glow: 'rgba(27, 27, 24, 0.06)',
        shadow: '0 40px 120px rgba(27, 27, 24, 0.14)',
        chart: ['#d4d4d8', '#a1a1aa', '#71717a', '#52525b'],
        sheetHeader: '#f5f5f3',
        sheetGrid: '#e3e3e0',
    },
};
