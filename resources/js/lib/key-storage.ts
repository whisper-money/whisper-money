const ENCRYPTION_KEY_NAME = 'encryption_key';

function isBrowser(): boolean {
    return typeof window !== 'undefined';
}

export function storeKey(key: string, persistent: boolean): void {
    if (!isBrowser()) return;

    if (persistent) {
        localStorage.setItem(ENCRYPTION_KEY_NAME, key);
    } else {
        sessionStorage.setItem(ENCRYPTION_KEY_NAME, key);
    }
}

export function getStoredKey(): string | null {
    if (!isBrowser()) return null;

    return (
        sessionStorage.getItem(ENCRYPTION_KEY_NAME) ||
        localStorage.getItem(ENCRYPTION_KEY_NAME)
    );
}

export function clearKey(): void {
    if (!isBrowser()) return;

    sessionStorage.removeItem(ENCRYPTION_KEY_NAME);
    localStorage.removeItem(ENCRYPTION_KEY_NAME);
}

export function isKeyPersistent(): boolean {
    if (!isBrowser()) return false;

    return localStorage.getItem(ENCRYPTION_KEY_NAME) !== null;
}
