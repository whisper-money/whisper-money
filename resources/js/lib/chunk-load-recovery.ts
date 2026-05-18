const CHUNK_LOAD_RELOAD_STORAGE_KEY =
    'whisper-money:chunk-load-reload-asset-signature';

const CHUNK_LOAD_ERROR_PATTERNS = [
    /Failed to fetch dynamically imported module/i,
    /error loading dynamically imported module/i,
    /Importing a module script failed/i,
    /Load failed for module script/i,
    /Unable to preload CSS/i,
    /ChunkLoadError/i,
    /Loading chunk \d+ failed/i,
];

let inMemoryReloadedAssetSignature: string | null = null;

interface ChunkLoadRecoveryOptions {
    assetSignature?: string;
    reload?: () => void;
    storage?: Storage;
}

export function getCurrentAssetSignature(): string {
    const buildAssetScripts = Array.from(document.scripts)
        .map((script) => script.src)
        .filter((src) => src.includes('/build/assets/'))
        .sort();

    return buildAssetScripts.join('|') || window.location.href;
}

export function isChunkLoadError(value: unknown): boolean {
    const message = getErrorMessage(value);

    return CHUNK_LOAD_ERROR_PATTERNS.some((pattern) => pattern.test(message));
}

export function reloadOnChunkLoadError(
    value: unknown,
    options: ChunkLoadRecoveryOptions = {},
): boolean {
    if (!isChunkLoadError(value)) {
        return false;
    }

    const assetSignature = options.assetSignature ?? getCurrentAssetSignature();

    if (hasAlreadyReloadedForAssetSignature(assetSignature, options.storage)) {
        return false;
    }

    markReloadedForAssetSignature(assetSignature, options.storage);
    (options.reload ?? (() => window.location.reload()))();

    return true;
}

export function installChunkLoadRecovery(): void {
    window.addEventListener('unhandledrejection', (event) => {
        if (
            reloadOnChunkLoadError(event.reason, {
                storage: window.sessionStorage,
            })
        ) {
            event.preventDefault();
        }
    });

    window.addEventListener('error', (event) => {
        if (
            reloadOnChunkLoadError(event.error ?? event.message, {
                storage: window.sessionStorage,
            })
        ) {
            event.preventDefault();
        }
    });
}

function getErrorMessage(value: unknown): string {
    if (value instanceof Error) {
        return `${value.name}: ${value.message}`;
    }

    if (typeof value === 'string') {
        return value;
    }

    if (typeof value === 'object' && value !== null && 'message' in value) {
        return String(value.message);
    }

    return '';
}

function hasAlreadyReloadedForAssetSignature(
    assetSignature: string,
    storage?: Storage,
): boolean {
    if (inMemoryReloadedAssetSignature === assetSignature) {
        return true;
    }

    try {
        return (
            storage?.getItem(CHUNK_LOAD_RELOAD_STORAGE_KEY) === assetSignature
        );
    } catch {
        return false;
    }
}

function markReloadedForAssetSignature(
    assetSignature: string,
    storage?: Storage,
): void {
    inMemoryReloadedAssetSignature = assetSignature;

    try {
        storage?.setItem(CHUNK_LOAD_RELOAD_STORAGE_KEY, assetSignature);
    } catch {
        return;
    }
}
