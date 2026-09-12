/**
 * Where the encryption key lives between unlocks.
 *
 * A browser configured to block site data throws `SecurityError` on the
 * property read itself, so `typeof window` guards nothing (PHP-LARAVEL-5V,
 * same failure class as PHP-LARAVEL-57 / PHP-LARAVEL-4Y). `clearKey()` runs in
 * the login page's mount effect, so the unguarded version white-screened the
 * one page a locked-out user needs.
 *
 * Everything here fails quietly, degrading to the normal unlock prompt.
 * ./safe-storage's docblock asks that an encryption key not be given a silent
 * write without telling the user, and this is a deliberate exception: the key
 * now only decrypts a legacy user's data on the way in, so a lost write costs
 * one more password prompt on a flow that is being retired. That flow is
 * components/unlock-message-dialog.tsx, the one caller of storeKey: it reads
 * as live code because it still has to work for those users, not because the
 * feature is. If keys are ever stored for real again, storeKey has to report
 * the failure and its caller has to surface it.
 *
 * Its read/write helpers are still not reused, for the duller reason that they
 * only reach localStorage while the key also lives in sessionStorage.
 *
 * The catches are layered because the failures are: `getStorage` absorbs the
 * throwing property read, the try around it absorbs `getItem`/`setItem`
 * themselves, which still throw on an exhausted quota or in private mode.
 */

import { getStorage } from './safe-storage';

const ENCRYPTION_KEY_NAME = 'encryption_key';

export function storeKey(key: string, persistent: boolean): void {
    try {
        getStorage(persistent ? 'local' : 'session')?.setItem(
            ENCRYPTION_KEY_NAME,
            key,
        );
    } catch {
        // Blocked storage or an exhausted quota: the key does not survive, and
        // the next visit asks for the password again.
    }
}

export function getStoredKey(): string | null {
    try {
        return (
            getStorage('session')?.getItem(ENCRYPTION_KEY_NAME) ||
            getStorage('local')?.getItem(ENCRYPTION_KEY_NAME) ||
            null
        );
    } catch {
        return null;
    }
}

export function clearKey(): void {
    // One try per area on purpose: a throw while clearing the session copy
    // must not leave the persistent one behind on a shared browser.
    for (const area of ['session', 'local'] as const) {
        try {
            getStorage(area)?.removeItem(ENCRYPTION_KEY_NAME);
        } catch {
            // Storage that cannot be reached is storage holding nothing.
        }
    }
}
