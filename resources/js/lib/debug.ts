import { readStoredValue } from './safe-storage';

export function consoleDebug(...args: unknown[]): void {
    if (typeof window === 'undefined') return;

    // Through safe-storage because a logging helper must never be the thing
    // that throws: blocked site data makes a bare read raise SecurityError.
    const isDebugEnabled = readStoredValue('debug') === 'true';
    const isLocalhost = window.location.hostname === 'localhost';
    const isTestDomain = window.location.hostname.includes('.test');

    if (isDebugEnabled || isLocalhost || isTestDomain) {
        console.log('[DEBUG]', ...args);
    }
}
