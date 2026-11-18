/**
 * A short, stable hash of a text (cyrb53), for ids of rows the bank gives no
 * id to. Not for anything secret.
 */
export function stableHash(text: string): string {
    let h1 = 0xdeadbeef;
    let h2 = 0x41c6ce57;

    for (let index = 0; index < text.length; index++) {
        const code = text.charCodeAt(index);
        h1 = Math.imul(h1 ^ code, 2654435761);
        h2 = Math.imul(h2 ^ code, 1597334677);
    }

    h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507);
    h1 ^= Math.imul(h2 ^ (h2 >>> 13), 3266489909);
    h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507);
    h2 ^= Math.imul(h1 ^ (h1 >>> 13), 3266489909);

    return (4294967296 * (2097151 & h2) + (h1 >>> 0)).toString(36);
}

/**
 * Ids for the rows of one file that has none of its own: a hash of what the
 * row says, plus its ordinal among identical rows. Two equal coffees on one
 * day stay two rows, and the same file read twice gets the same ids.
 */
export function rowIdFactory(prefix: string): (parts: unknown[]) => string {
    const occurrences = new Map<string, number>();

    return (parts) => {
        const key = stableHash(parts.map(String).join('|'));
        const ordinal = occurrences.get(key) ?? 0;

        occurrences.set(key, ordinal + 1);

        return `${prefix}-${key}-${ordinal}`;
    };
}
