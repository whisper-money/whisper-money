/**
 * Jump to a heading without a page load, leaving the hash in the address bar so
 * the position can be shared and the back button still works.
 */
export function scrollToAnchor(hash: string): boolean {
    const target = document.getElementById(hash.replace(/^#/, ''));

    if (!target) {
        return false;
    }

    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    history.pushState(null, '', `#${hash.replace(/^#/, '')}`);

    return true;
}
