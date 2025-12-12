import type { UUID } from '@/types/uuid';
import { db } from './dexie-db';

const USER_ID_KEY = 'current_user_id';

function isBrowser(): boolean {
    return typeof window !== 'undefined';
}

export async function getStoredUserId(): Promise<UUID | null> {
    if (!isBrowser()) return null;

    try {
        await db.open();
        const metadata = await db.sync_metadata.get(USER_ID_KEY);
        return (metadata?.value as UUID) || null;
    } catch {
        return null;
    }
}

export async function setStoredUserId(userId: UUID): Promise<void> {
    if (!isBrowser()) return;

    try {
        await db.open();
        await db.sync_metadata.put({ key: USER_ID_KEY, value: userId });
    } catch (error) {
        console.error('Failed to store user ID:', error);
    }
}

export async function clearAllUserData(): Promise<void> {
    if (!isBrowser()) return;

    // Clear all tables including sync_metadata (which contains last_sync timestamps)
    // This ensures a fresh start for the new user without stale sync timestamps
    try {
        await db.open();
        await Promise.all([
            db.transactions.clear(),
            db.accounts.clear(),
            db.categories.clear(),
            db.banks.clear(),
            db.automation_rules.clear(),
            db.account_balances.clear(),
            db.sync_metadata.clear(),
            db.pending_changes.clear(),
        ]);
    } catch {
        // If clearing fails, delete and recreate the database
        await db.delete();
        await db.open();
    }
}

export async function handleUserChange(newUserId: UUID): Promise<boolean> {
    const storedUserId = await getStoredUserId();

    // Different user logged in - clear all data and set new user
    if (storedUserId && storedUserId !== newUserId) {
        await clearAllUserData();
        await setStoredUserId(newUserId);
        return true;
    }

    // No stored user ID (first time or after signup/clear) - store the new user ID
    if (!storedUserId) {
        await setStoredUserId(newUserId);
    }

    // Same user or just stored - no clearing needed
    return false;
}
