import { type Locale } from './copy';

export const SCENE_IDS = [
    'hook',
    'noTracking',
    'spreadsheet',
    'turn',
    'sync',
    'dashboard',
    'budgets',
    'privacy',
    'comparison',
    'cta',
] as const;

export type SceneId = (typeof SCENE_IDS)[number];

/** `spoken` replaces `text` in the TTS request when the voice misreads it; the screen never shows either. */
type VoiceLine = { text: string; spoken?: string };

export const voiceLines: Record<Locale, Record<SceneId, VoiceLine>> = {
    en: {
        hook: { text: 'Where did your money go last month?' },
        noTracking: { text: "If you don't track it, you're just guessing." },
        spreadsheet: { text: 'And spreadsheets? Hours of typing… and they break when you need them most.' },
        turn: { text: "There's a better way: Whisper Money." },
        sync: { text: 'Connect your banks, and every transaction syncs and sorts itself.' },
        dashboard: { text: 'Net worth, cashflow and every account, at a glance.' },
        budgets: { text: 'Budgets that warn you before you overspend, not after.' },
        privacy: { text: "And it's private by design. Your data is yours." },
        comparison: { text: 'Stop guessing. Stop typing. Minutes a month, not hours.' },
        cta: {
            text: 'All your money in one place. Start free at whisper.money.',
            spoken: 'All your money in one place. Start free at whisper dot money.',
        },
    },
    es: {
        hook: { text: '¿Dónde se fue tu dinero el mes pasado?' },
        noTracking: { text: 'Si no lo controlas, vas a ciegas.' },
        spreadsheet: { text: '¿Y el Excel? Horas tecleando… y se rompe cuando más lo necesitas.' },
        turn: { text: 'Hay una forma mejor: Whisper Money.' },
        sync: { text: 'Conecta tus bancos y cada movimiento se sincroniza y se categoriza solo.' },
        dashboard: { text: 'Patrimonio, flujo de caja y todas tus cuentas, de un vistazo.' },
        budgets: { text: 'Presupuestos que te avisan antes de pasarte, no después.' },
        privacy: { text: 'Y privado por diseño: tus datos son solo tuyos.' },
        comparison: { text: 'Ni adivinar, ni teclear. Minutos al mes, no horas.' },
        cta: {
            text: 'Todo tu dinero en un solo sitio. Empieza gratis en whisper.money.',
            spoken: 'Todo tu dinero en un solo sitio. Empieza gratis en whisper punto money.',
        },
    },
};

export function spokenLine(locale: Locale, scene: SceneId): string {
    const line = voiceLines[locale][scene];

    return line.spoken ?? line.text;
}
