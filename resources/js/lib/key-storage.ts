const ENCRYPTION_KEY_NAME = 'encryption_key';

export function storeKey(key: string, persistent: boolean): void {
    if (persistent) {
        localStorage.setItem(ENCRYPTION_KEY_NAME, key);
    } else {
        sessionStorage.setItem(ENCRYPTION_KEY_NAME, key);
    }
}

export function getStoredKey(): string | null {
    return (
        sessionStorage.getItem(ENCRYPTION_KEY_NAME) ||
        localStorage.getItem(ENCRYPTION_KEY_NAME)
    );
}

export function clearKey(): void {
    sessionStorage.removeItem(ENCRYPTION_KEY_NAME);
    localStorage.removeItem(ENCRYPTION_KEY_NAME);
}

export function isKeyPersistent(): boolean {
    return localStorage.getItem(ENCRYPTION_KEY_NAME) !== null;
}
