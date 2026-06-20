import { decrypt } from '@/lib/crypto';
import { renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useDecryptTransactions } from './use-decrypt-transactions';

vi.mock('axios');
vi.mock('@/contexts/encryption-key-context', () => ({
    useEncryptionKey: () => ({ isKeySet: true }),
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { hasEncryptedTransactions: true } }),
}));
vi.mock('@/lib/key-storage', () => ({
    getStoredKey: () => 'stored-key',
}));
vi.mock('@/lib/crypto', () => ({
    importKey: vi.fn(async () => 'crypto-key'),
    decrypt: vi.fn(async (text: string) => `plain:${text}`),
}));

interface EncryptedRow {
    id: string;
    description: string;
    description_iv: string | null;
    notes: string | null;
    notes_iv: string | null;
}

interface BulkBody {
    transactions: { id: string }[];
}

const reloadMock = vi.fn();

function makeRow(id: string): EncryptedRow {
    return {
        id,
        description: `cipher-${id}`,
        description_iv: `iv-${id}`,
        notes: null,
        notes_iv: null,
    };
}

beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(decrypt).mockImplementation(
        async (text: string) => `plain:${text}`,
    );
    Object.defineProperty(window, 'location', {
        configurable: true,
        value: { reload: reloadMock },
    });
});

describe('useDecryptTransactions', () => {
    it('migrates every encrypted row even as the set shrinks under it', async () => {
        // The server returns only the first still-encrypted rows on each
        // fetch; each bulk update removes the patched rows. A cursor that
        // advanced its offset would skip the rows shifting into freed slots.
        let remaining = ['1', '2', '3', '4', '5'].map(makeRow);

        vi.mocked(axios.get).mockImplementation(async () => ({
            data: { data: remaining.slice(0, 2), next_page_url: null },
        }));
        vi.mocked(axios.patch).mockImplementation(async (_url, body) => {
            const ids = (body as BulkBody).transactions.map((t) => t.id);
            remaining = remaining.filter((row) => !ids.includes(row.id));
            return { data: {} };
        });

        renderHook(() => useDecryptTransactions());

        await waitFor(() => expect(reloadMock).toHaveBeenCalledTimes(1));
        expect(remaining).toHaveLength(0);
    });

    it('stops instead of looping forever when rows never decrypt', async () => {
        vi.mocked(decrypt).mockRejectedValue(new Error('wrong key'));
        vi.mocked(axios.get).mockResolvedValue({
            data: { data: [makeRow('1'), makeRow('2')], next_page_url: null },
        });

        renderHook(() => useDecryptTransactions());

        await waitFor(() => expect(reloadMock).toHaveBeenCalledTimes(1));
        expect(axios.patch).not.toHaveBeenCalled();
    });
});
