export const LANGUAGE_OPTIONS = [
    { code: 'en', label: 'English' },
    { code: 'es', label: 'Español' },
    { code: 'fr', label: 'Français' },
] as const;

export type LocaleCode = 'en' | 'es' | 'fr';
