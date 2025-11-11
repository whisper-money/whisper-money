function ensureCryptoAvailable(): void {
    if (!window.crypto || !window.crypto.subtle) {
        throw new Error(
            'Web Crypto API is not available. Please ensure you are using HTTPS or localhost.',
        );
    }
}

export async function getKeyFromPassword(password: string): Promise<CryptoKey> {
    ensureCryptoAvailable();
    const encoder = new TextEncoder();
    return await window.crypto.subtle.importKey(
        'raw',
        encoder.encode(password),
        'PBKDF2',
        false,
        ['deriveBits', 'deriveKey'],
    );
}

export async function getAESKeyFromPBKDF(
    key: CryptoKey,
    salt: Uint8Array,
): Promise<CryptoKey> {
    ensureCryptoAvailable();
    return await window.crypto.subtle.deriveKey(
        {
            name: 'PBKDF2',
            salt,
            iterations: 100000,
            hash: 'SHA-256',
        },
        key,
        { name: 'AES-GCM', length: 256 },
        true,
        ['encrypt', 'decrypt'],
    );
}

export async function encrypt(
    plaintext: string,
    key: CryptoKey,
): Promise<{ encrypted: string; iv: string }> {
    ensureCryptoAvailable();
    const encoder = new TextEncoder();
    const data = encoder.encode(plaintext);

    const iv = window.crypto.getRandomValues(new Uint8Array(12));

    const encryptedBuffer = await window.crypto.subtle.encrypt(
        {
            name: 'AES-GCM',
            iv,
        },
        key,
        data,
    );

    return {
        encrypted: bufferToBase64(encryptedBuffer),
        iv: bufferToBase64(iv),
    };
}

export async function decrypt(
    encrypted: string,
    key: CryptoKey,
    iv: string,
): Promise<string> {
    ensureCryptoAvailable();
    const encryptedBuffer = base64ToBuffer(encrypted);
    const ivBuffer = base64ToBuffer(iv);

    const decryptedBuffer = await window.crypto.subtle.decrypt(
        {
            name: 'AES-GCM',
            iv: ivBuffer,
        },
        key,
        encryptedBuffer,
    );

    const decoder = new TextDecoder();
    return decoder.decode(decryptedBuffer);
}

export function generateSalt(): Uint8Array {
    ensureCryptoAvailable();
    return window.crypto.getRandomValues(new Uint8Array(16));
}

export function bufferToBase64(buffer: ArrayBuffer | Uint8Array): string {
    const bytes =
        buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary);
}

export function base64ToBuffer(base64: string): Uint8Array {
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }
    return bytes;
}

export async function exportKey(key: CryptoKey): Promise<string> {
    ensureCryptoAvailable();
    const exported = await window.crypto.subtle.exportKey('raw', key);
    return bufferToBase64(exported);
}

export async function importKey(keyString: string): Promise<CryptoKey> {
    ensureCryptoAvailable();
    const keyBuffer = base64ToBuffer(keyString);
    return await window.crypto.subtle.importKey(
        'raw',
        keyBuffer,
        { name: 'AES-GCM', length: 256 },
        true,
        ['encrypt', 'decrypt'],
    );
}
