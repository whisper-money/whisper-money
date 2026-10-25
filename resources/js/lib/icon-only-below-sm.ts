/**
 * Below `sm` the bar above the transactions list (and the mobile app header)
 * has no room for labels, so a collapsible button drops to its icon alone,
 * squared around it, the way Export and Columns already do. Spelled out so
 * Tailwind sees every class.
 */
export const ICON_ONLY_BELOW_SM = {
    button: 'w-9 px-0 has-[>svg]:px-0 sm:w-auto sm:px-4 sm:has-[>svg]:px-3',
    label: 'hidden sm:inline',
};
