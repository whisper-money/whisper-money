import type { UUID } from '@/types/uuid';
import { db } from './dexie-db';

const USER_ID_KEY = 'wm_user_id';

function isBrowser(): boolean {
    return typeof window !== 'undefined';
}

export function getStoredUserId(): UUID | null {
    if (!isBrowser()) return null;

    return localStorage.getItem(USER_ID_KEY) as UUID | null;
}

export function setStoredUserId(userId: UUID): void {
    if (!isBrowser()) return;

    localStorage.setItem(USER_ID_KEY, userId);
}

export async function clearAllUserData(): Promise<void> {
    if (!isBrowser()) return;

    await db.delete();

    const keysToPreserve = [USER_ID_KEY];
    const keysToRemove: string[] = [];

    for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        if (key && !keysToPreserve.includes(key)) {
            keysToRemove.push(key);
        }
    }

    keysToRemove.forEach((key) => localStorage.removeItem(key));

    sessionStorage.clear();
}

export async function handleUserChange(newUserId: UUID): Promise<boolean> {
    const storedUserId = getStoredUserId();

    if (storedUserId && storedUserId !== newUserId) {
        await clearAllUserData();
        setStoredUserId(newUserId);
        return true;
    }

    if (!storedUserId) {
        setStoredUserId(newUserId);
    }

    return false;
}
