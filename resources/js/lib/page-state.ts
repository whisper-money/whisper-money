import type { SharedData } from '@/types';
import { setCurrencyDecimals } from '@/utils/currency';
import { setTranslations } from '@/utils/i18n';

/**
 * Seed the module-level state that every render reads before its first paint
 * from the Inertia page props.
 *
 * One function rather than a pair of calls at each site, because the sites
 * drifted: `app.tsx` seeded both, `ssr.tsx` only the currency scale, so the
 * server-rendered pass emitted English copy to a Spanish reader and flipped to
 * the translation on hydration. Anything else a render has to know before its
 * first paint belongs here too, for the same reason.
 */
export function seedPageState(props: Partial<SharedData> | undefined): void {
    setTranslations(props?.translations ?? {});
    setCurrencyDecimals(props?.currencies?.decimals);
}
