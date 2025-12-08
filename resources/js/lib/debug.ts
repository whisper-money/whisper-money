export function consoleDebug(...args: unknown[]): void {
    if (typeof window === 'undefined') return;

    const isDebugEnabled = localStorage.getItem('debug') === 'true';
    const isLocalhost = window.location.hostname === 'localhost';
    const isTestDomain = window.location.hostname.includes('.test');

    if (isDebugEnabled || isLocalhost || isTestDomain) {
        console.log('[DEBUG]', ...args);
    }
}
